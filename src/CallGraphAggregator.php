<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

use Psr\Log\LoggerInterface;

/**
 * Builds the aggregated, pruned call graph for a report by streaming its events
 * through {@see SpxReportParser} into a {@see CallNode} tree.
 *
 * PHPStan has no recursive type aliases, so CallTreeNode below describes a single
 * level and deeper children are typed as opaque arrays. The real recursion is
 * carried by the {@see CallNode} class.
 *
 * @phpstan-type CallTreeNode array{
 *     name: string,
 *     value: int,
 *     calls: int,
 *     file?: string,
 *     lineNumber?: int,
 *     children: list<array<string, mixed>>,
 * }
 * @phpstan-import-type FunctionRow from SpxReportParser
 */
class CallGraphAggregator
{
    /** Synthetic root sentinel; see {@see CallNode::$fnIdx}. */
    private const ROOT_FN_IDX = -1;

    public function __construct(
        private readonly SpxReportStore $reportStore,
        private readonly LoggerInterface $logger = new StderrLogger(),
    ) {}

    /**
     * @param list<string> $rootStack optional path of nested calls to focus on; see
     *                                 {@see self::resolveFocusNode()} for the format
     * @return array{metric: string, root: CallTreeNode}
     */
    public function getAggregatedCallGraph(
        string $reportKey,
        string $metric,
        float $pruningRelativeThreshold,
        array $rootStack = [],
    ): array {
        $enabledMetrics = $this->reportStore->getEnabledMetrics($reportKey);

        $metricIdx = array_search($metric, $enabledMetrics, true);
        if ($metricIdx === false) {
            throw new \InvalidArgumentException(
                "Metric '$metric' not recorded in this report. Available: "
                . implode(', ', $enabledMetrics),
            );
        }

        ['root' => $root, 'functions' => $functions]
            = $this->readCallTreeFile($reportKey, $metricIdx);

        $focus = $this->resolveFocusNode($root, $functions, $rootStack, $reportKey);

        // Prune relative to the focused subtree's own value; the synthetic root has
        // no value of its own, so use the sum of its children there.
        $total = $focus->fnIdx === self::ROOT_FN_IDX
            ? array_sum(array_map(fn(CallNode $child): int => $child->value, $focus->children))
            : $focus->value;
        if ($total <= 0) {
            throw new \RuntimeException(
                "Metric '$metric' has a total value of 0 under the requested root in "
                . "report '$reportKey': nothing to aggregate",
            );
        }
        $this->pruneAndSortCallTree($focus, $total, $pruningRelativeThreshold);

        return ['metric' => $metric, 'root' => $this->nodeToArray($focus, $functions, $reportKey)];
    }

    /**
     * Descends from $root following $rootStack to re-root the graph on a specific
     * call. Each entry identifies one child of the current node by its function
     * name, optionally prefixed with "<lineNumber>:" to disambiguate homonyms (e.g.
     * "423:Composer\Autoload\ClassLoader::loadClass"). An empty stack keeps the
     * synthetic root. A non-matching entry is a caller error.
     *
     * @param list<FunctionRow> $functions
     * @param list<string> $rootStack
     */
    private function resolveFocusNode(
        CallNode $root,
        array $functions,
        array $rootStack,
        string $reportKey,
    ): CallNode {
        $node = $root;
        foreach ($rootStack as $depth => $entry) {
            [$lineNumber, $name] = self::parseRootStackEntry($entry);

            $match = null;
            foreach ($node->children as $child) {
                $identity = self::lookupFunction($child, $functions);
                if ($identity === null || $identity['name'] !== $name) {
                    continue;
                }
                if ($lineNumber !== null && $identity['lineNumber'] !== $lineNumber) {
                    continue;
                }
                $match = $child;
                break;
            }

            if ($match === null) {
                throw new \InvalidArgumentException(sprintf(
                    "Root stack entry '%s' (depth %d) matches no call under path "
                    . "[%s] in report '%s'",
                    $entry,
                    $depth,
                    implode(' > ', array_slice($rootStack, 0, $depth)),
                    $reportKey,
                ));
            }
            $node = $match;
        }

        return $node;
    }

    /**
     * Splits "<lineNumber>:name" into [lineNumber, name]; an entry with no numeric
     * prefix yields [null, entry]. The name may itself contain colons (e.g. a
     * "phar://" path or "Foo::bar"), hence the anchored prefix match.
     *
     * @return array{0: ?int, 1: string}
     */
    private static function parseRootStackEntry(string $entry): array
    {
        if (preg_match('/^(\d+):(.+)$/', $entry, $matches) === 1) {
            return [(int) $matches[1], $matches[2]];
        }

        return [null, $entry];
    }

    /**
     * Streams the report once, building the {@see CallNode} tree and resolving the
     * function table. Array conversion is left to {@see self::nodeToArray()} so
     * pruning runs on the typed graph.
     *
     * @return array{root: CallNode, functions: list<FunctionRow>}
     */
    private function readCallTreeFile(string $key, int $metricIdx): array
    {
        $reportParser = new SpxReportParser();
        $functions = [];
        $section = null;

        $metricColumn = 2 + $metricIdx;
        $root = new CallNode(self::ROOT_FN_IDX);
        $stack = [$root];

        foreach ($this->reportStore->streamReportLines($key) as $line) {
            if ($line === '[functions]') {
                $section = 'functions';
                continue;
            }
            if ($line === '[events]') {
                $section = 'events';
                continue;
            }
            if ($line === '') {
                continue;
            }

            if ($section === 'functions') {
                $functions[] = $reportParser->parseFunctionLine($line);
            } elseif ($section === 'events') {
                $event = $reportParser->parseEventLine($line);
                $fnIdx = $event[0];
                $isStart = $event[1] === 1;
                if (!isset($event[$metricColumn])) {
                    throw new \RuntimeException(sprintf(
                        "Event line in report '%s' has no value for metric column %d",
                        $key,
                        $metricColumn,
                    ));
                }
                $metricValue = $event[$metricColumn];

                if ($isStart) {
                    $parent = end($stack);

                    if (!isset($parent->children[$fnIdx])) {
                        $parent->children[$fnIdx] = new CallNode($fnIdx);
                    }

                    $child = $parent->children[$fnIdx];
                    $child->startValue = $metricValue;
                    $child->calls++;

                    $stack[] = $child;
                } else {
                    if (count($stack) > 1) {
                        $node = array_pop($stack);
                        $node->value += $metricValue - $node->startValue;
                    }
                }
            }
        }

        if (count($stack) > 1) {
            $this->logger->warning(sprintf(
                "report '%s' appears truncated: %d unclosed call(s) at EOF",
                $key,
                count($stack) - 1,
            ));
        }

        return ['root' => $root, 'functions' => $functions];
    }

    /**
     * Recursively drops children below $pruningRelativeThreshold of $total, then
     * sorts the survivors by value desc.
     */
    private function pruneAndSortCallTree(
        CallNode $node,
        float $total,
        float $pruningRelativeThreshold,
    ): void {
        $node->children = array_filter(
            $node->children,
            fn(CallNode $child): bool => ($child->value / $total) >= $pruningRelativeThreshold,
        );
        uasort(
            $node->children,
            fn(CallNode $left, CallNode $right): int => $right->value <=> $left->value,
        );

        foreach ($node->children as $child) {
            $this->pruneAndSortCallTree($child, $total, $pruningRelativeThreshold);
        }
    }

    /**
     * @param list<FunctionRow> $functions
     * @return CallTreeNode
     */
    private function nodeToArray(CallNode $node, array $functions, string $reportKey): array
    {
        $children = array_map(
            fn(CallNode $child): array => $this->nodeToArray($child, $functions, $reportKey),
            array_values($node->children),
        );

        $identity = self::lookupFunction($node, $functions);
        if ($node->fnIdx === self::ROOT_FN_IDX) {
            $name = 'root';
            $location = null;
        } elseif ($identity !== null) {
            $name = $identity['name'];
            $location = ['file' => $identity['file'], 'lineNumber' => $identity['lineNumber']];
        } else {
            $this->logger->warning(sprintf(
                "report '%s' references undefined function index %d; file likely corrupted",
                $reportKey,
                $node->fnIdx,
            ));
            $name = "fn#{$node->fnIdx}";
            $location = null;
        }

        $out = [
            'name'  => $name,
            'value' => $node->value,
            'calls' => $node->calls,
        ];
        if ($location !== null) {
            $out['file'] = $location['file'];
            $out['lineNumber'] = $location['lineNumber'];
        }
        $out['children'] = $children;

        return $out;
    }

    /**
     * Looks up a node's name and source location in the function table, or null if
     * its index is missing (corrupted report). The synthetic root resolves to 'root'.
     *
     * @param list<FunctionRow> $functions
     * @return array{name: string, file: string, lineNumber: int}|null
     */
    private static function lookupFunction(CallNode $node, array $functions): ?array
    {
        if ($node->fnIdx === self::ROOT_FN_IDX) {
            return ['name' => 'root', 'file' => '', 'lineNumber' => 0];
        }
        if (isset($functions[$node->fnIdx])) {
            $fn = $functions[$node->fnIdx];

            return [
                'name' => $fn['functionName'],
                'file' => $fn['file'],
                'lineNumber' => $fn['lineNumber'],
            ];
        }

        return null;
    }
}
