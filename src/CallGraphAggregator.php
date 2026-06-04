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
 * @phpstan-type FlatProfileEntry array{
 *     name: string,
 *     file: string,
 *     lineNumber: int,
 *     calls: int,
 *     exclusive: int,
 *     exclusiveRelative: float,
 *     inclusive: int,
 *     inclusiveRelative: float,
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
        $metricIdx = $this->resolveMetricIndex($reportKey, $metric);

        ['root' => $root, 'functions' => $functions]
            = $this->readCallTreeFile($reportKey, $metricIdx);

        $focus = $this->resolveFocusNode(
            $root,
            $functions,
            $rootStack,
            $reportKey,
        );

        // Prune relative to the focused subtree's own value; the synthetic root has
        // no value of its own, so use the sum of its children there.
        $total = $focus->fnIdx === self::ROOT_FN_IDX
            ? array_sum(
                array_map(
                    fn(CallNode $child): int => $child->value,
                    $focus->children,
                ),
            )
            : $focus->value;
        if ($total <= 0) {
            throw new \RuntimeException(
                "Metric '$metric' has a total value of 0 under the requested root in "
                . "report '$reportKey': nothing to aggregate",
            );
        }

        $this->pruneAndSortCallTree(
            $focus,
            $total,
            $pruningRelativeThreshold,
        );

        return [
            'metric' => $metric,
            'root' => $this->nodeToArray($focus, $functions, $reportKey),
        ];
    }

    /**
     * Builds the flat profile: per-function metric totals aggregated across every
     * call context, sorted by exclusive (self) metric descending and capped at
     * $limit entries.
     *
     * Exclusive metric is a node's own value minus its children's, summed over all
     * contexts; it stays additive under recursion. Inclusive metric counts a
     * function's value only at the outermost frame of any recursive chain, so a
     * function recursing into itself is not double-counted.
     *
     * @return list<FlatProfileEntry>
     */
    public function getFlatProfile(
        string $reportKey,
        string $metric,
        int $limit,
    ): array {
        $metricIdx = $this->resolveMetricIndex($reportKey, $metric);

        ['root' => $root, 'functions' => $functions]
            = $this->readCallTreeFile($reportKey, $metricIdx);

        // The whole-program total is the sum of the top-level calls' inclusive
        // values; every exclusive contribution telescopes up to it.
        $total = array_sum(
            array_map(fn(CallNode $child): int => $child->value, $root->children),
        );
        if ($total <= 0) {
            throw new \RuntimeException(
                "Metric '$metric' has a total value of 0 in report '$reportKey': "
                . 'nothing to aggregate',
            );
        }

        /** @var array<int, array{calls: int, exclusive: int, inclusive: int}> $stats */
        $stats = [];
        $this->accumulateFlatStats($root, [], $stats);

        $entries = [];
        foreach ($stats as $fnIdx => $stat) {
            $identity = self::lookupFunction($fnIdx, $functions);
            if ($identity === null) {
                $this->logger->warning(sprintf(
                    "report '%s' references undefined function index %d; file likely corrupted",
                    $reportKey,
                    $fnIdx,
                ));
                $identity = ['name' => "fn#$fnIdx", 'file' => '', 'lineNumber' => 0];
            }

            $entries[] = [
                'name'              => $identity['name'],
                'file'              => $identity['file'],
                'lineNumber'        => $identity['lineNumber'],
                'calls'             => $stat['calls'],
                'exclusive'         => $stat['exclusive'],
                'exclusiveRelative' => (float) $stat['exclusive'] / $total,
                'inclusive'         => $stat['inclusive'],
                'inclusiveRelative' => (float) $stat['inclusive'] / $total,
            ];
        }

        usort(
            $entries,
            fn(array $left, array $right): int => $right['exclusive'] <=> $left['exclusive'],
        );

        return array_slice($entries, 0, max(1, $limit));
    }

    /**
     * Builds the inverted (callers) call graph anchored on $function: a tree rooted
     * on the function whose children are its direct callers, theirs their callers,
     * up to the entry point. Each caller path carries the share of the function's
     * metric that flows through it. The bottom-up counterpart of
     * {@see self::getAggregatedCallGraph()}.
     *
     * $function is a name as shown in the call graph; a function name uniquely
     * identifies one function, so a name matching several entries is rejected. To
     * stay consistent with the flat profile's inclusive metric, only the outermost
     * frame of any recursive chain is attributed, so the root value equals that
     * function's inclusive total.
     *
     * @return array{metric: string, root: CallTreeNode}
     */
    public function getCallers(
        string $reportKey,
        string $function,
        string $metric,
        float $pruningRelativeThreshold,
    ): array {
        $metricIdx = $this->resolveMetricIndex($reportKey, $metric);

        ['root' => $root, 'functions' => $functions]
            = $this->readCallTreeFile($reportKey, $metricIdx);

        $targetFnIdx = null;
        foreach ($functions as $fnIdx => $fn) {
            if ($fn['functionName'] !== $function) {
                continue;
            }

            if ($targetFnIdx !== null) {
                throw new \InvalidArgumentException(
                    "Function name '$function' matches several functions in report "
                    . "'$reportKey'; a function name is expected to be unique",
                );
            }

            $targetFnIdx = $fnIdx;
        }

        if ($targetFnIdx === null) {
            throw new \InvalidArgumentException(
                "Function '$function' not found in report '$reportKey'",
            );
        }

        $inverted = new CallNode($targetFnIdx);
        $this->collectCallers($root, [], $targetFnIdx, $inverted);

        $total = $inverted->value;
        if ($total <= 0) {
            throw new \RuntimeException(
                "Function '$function' has a total value of 0 for metric '$metric' in "
                . "report '$reportKey': nothing to invert",
            );
        }

        $this->pruneAndSortCallTree(
            $inverted,
            $total,
            $pruningRelativeThreshold,
        );

        return [
            'metric' => $metric,
            'root' => $this->nodeToArray($inverted, $functions, $reportKey),
        ];
    }

    /**
     * Resolves a metric name to its column offset within the report's event tuples,
     * rejecting metrics not actually recorded in the targeted report.
     */
    private function resolveMetricIndex(string $reportKey, string $metric): int
    {
        $enabledMetrics = $this->reportStore->getEnabledMetrics($reportKey);

        $metricIdx = array_search($metric, $enabledMetrics, true);
        if ($metricIdx === false) {
            throw new \InvalidArgumentException(
                "Metric '$metric' not recorded in this report. Available: "
                . implode(', ', $enabledMetrics),
            );
        }

        return $metricIdx;
    }

    /**
     * Walks the call tree accumulating per-function exclusive/inclusive/calls
     * totals into $stats, keyed by function index. $ancestorFnIndexes is the set of
     * function indexes already open on the current path; it gates inclusive
     * counting so recursive frames contribute only once.
     *
     * @param array<int, true> $ancestorFnIndexes
     * @param array<int, array{calls: int, exclusive: int, inclusive: int}> $stats
     */
    private function accumulateFlatStats(
        CallNode $node,
        array $ancestorFnIndexes,
        array &$stats,
    ): void {
        foreach ($node->children as $child) {
            $childrenValue = array_sum(
                array_map(
                    fn(CallNode $grandChild): int => $grandChild->value,
                    $child->children,
                ),
            );

            if (!isset($stats[$child->fnIdx])) {
                $stats[$child->fnIdx] = [
                    'calls' => 0,
                    'exclusive' => 0,
                    'inclusive' => 0,
                ];
            }

            $stats[$child->fnIdx]['calls'] += $child->calls;
            $stats[$child->fnIdx]['exclusive'] += $child->value - $childrenValue;
            if (!isset($ancestorFnIndexes[$child->fnIdx])) {
                $stats[$child->fnIdx]['inclusive'] += $child->value;
            }

            $this->accumulateFlatStats(
                $child,
                $ancestorFnIndexes + [$child->fnIdx => true],
                $stats,
            );
        }
    }

    /**
     * Walks the call tree and, for each outermost occurrence of the target
     * function, attributes its inclusive value up the chain of $ancestors (nearest
     * caller last, synthetic root excluded), growing the $inverted callers tree.
     *
     * @param list<CallNode> $ancestors
     */
    private function collectCallers(
        CallNode $node,
        array $ancestors,
        int $targetFnIdx,
        CallNode $inverted,
    ): void {
        foreach ($node->children as $child) {
            $ancestorIsTarget = false;
            foreach ($ancestors as $ancestor) {
                if ($ancestor->fnIdx === $targetFnIdx) {
                    $ancestorIsTarget = true;

                    break;
                }
            }

            if ($child->fnIdx === $targetFnIdx && !$ancestorIsTarget) {
                $inverted->value += $child->value;
                $inverted->calls += $child->calls;

                $cursor = $inverted;
                for ($depth = count($ancestors) - 1; $depth >= 0; $depth--) {
                    $callerFnIdx = $ancestors[$depth]->fnIdx;
                    if (!isset($cursor->children[$callerFnIdx])) {
                        $cursor->children[$callerFnIdx] = new CallNode($callerFnIdx);
                    }

                    $cursor = $cursor->children[$callerFnIdx];
                    $cursor->value += $child->value;
                    $cursor->calls += $child->calls;
                }
            }

            $this->collectCallers(
                $child,
                [...$ancestors, $child],
                $targetFnIdx,
                $inverted,
            );
        }
    }

    /**
     * Descends from $root following $rootStack to re-root the graph on a specific
     * call. Each entry identifies one child of the current node by its function
     * name (e.g. "Composer\Autoload\ClassLoader::loadClass"). An empty stack keeps
     * the synthetic root. A non-matching entry is a caller error.
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
        foreach ($rootStack as $depth => $name) {
            $match = null;
            foreach ($node->children as $child) {
                $identity = self::lookupFunction($child->fnIdx, $functions);
                if ($identity === null || $identity['name'] !== $name) {
                    continue;
                }

                if ($match !== null) {
                    throw new \InvalidArgumentException(sprintf(
                        "Root stack entry '%s' (depth %d) matches several calls under "
                        . "path [%s] in report '%s'; a function name is expected to be "
                        . "unique",
                        $name,
                        $depth,
                        implode(' > ', array_slice($rootStack, 0, $depth)),
                        $reportKey,
                    ));
                }

                $match = $child;
            }

            if ($match === null) {
                throw new \InvalidArgumentException(sprintf(
                    "Root stack entry '%s' (depth %d) matches no call under path "
                    . "[%s] in report '%s'",
                    $name,
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
            $this->pruneAndSortCallTree(
                $child,
                $total,
                $pruningRelativeThreshold,
            );
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

        $identity = self::lookupFunction($node->fnIdx, $functions);
        if ($node->fnIdx === self::ROOT_FN_IDX) {
            $name = 'root';
            $location = null;
        } elseif ($identity !== null) {
            $name = $identity['name'];
            $location = [
                'file' => $identity['file'],
                'lineNumber' => $identity['lineNumber'],
            ];
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
     * Looks up a function index's name and source location in the function table, or
     * null if the index is missing (corrupted report). The synthetic root index
     * resolves to 'root'.
     *
     * @param list<FunctionRow> $functions
     * @return array{name: string, file: string, lineNumber: int}|null
     */
    private static function lookupFunction(int $fnIdx, array $functions): ?array
    {
        if ($fnIdx === self::ROOT_FN_IDX) {
            return ['name' => 'root', 'file' => '', 'lineNumber' => 0];
        }

        if (isset($functions[$fnIdx])) {
            $fn = $functions[$fnIdx];

            return [
                'name' => $fn['functionName'],
                'file' => $fn['file'],
                'lineNumber' => $fn['lineNumber'],
            ];
        }

        return null;
    }
}
