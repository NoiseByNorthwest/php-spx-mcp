<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp\Tools;

use NoiseByNorthwest\SpxMcp\CallGraphAggregator;
use NoiseByNorthwest\SpxMcp\ReportFilter;
use NoiseByNorthwest\SpxMcp\SpxReportStore;
use NoiseByNorthwest\SpxMcp\SystemClock;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Clock\ClockInterface;

/**
 * @phpstan-import-type CallTreeNode from CallGraphAggregator
 * @phpstan-import-type FlatProfileEntry from CallGraphAggregator
 * @phpstan-import-type ReportSummary from SpxReportStore
 */
class SpxToolProvider
{
    /**
     * Static set of metrics SPX can emit, advertised to the client for input
     * validation. Intentionally distinct from the per-report validation in
     * {@see CallGraphAggregator}, which rejects metrics not actually recorded
     * in the targeted report.
     *
     * @var list<string>
     */
    private const METRICS = [
        'wt', 'ct', 'it',                          // wall / CPU / idle time
        'zm', 'zmac', 'zmab', 'zmfc', 'zmfb',      // ZE memory usage + alloc/free count/bytes
        'zgr', 'zgb', 'zgc',                        // ZE GC runs / root buffer / collected
        'zif', 'zil', 'zuc', 'zuf', 'zuo',         // ZE incl. file/line + class/func/opcode
        'zo', 'ze',                                // ZE object count / error count
        'mor',                                     // process own RSS
        'io', 'ior', 'iow',                        // I/O total / read / written bytes
    ];

    public function __construct(
        private readonly SpxReportStore $reportStore,
        private readonly CallGraphAggregator $callGraphAggregator,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @return list<ReportSummary>
     */
    #[McpTool(
        name: 'find_reports',
        description: 'Find SPX profiling reports with server-side filters, returning '
        . 'their essential metadata: key, timestamp, descriptor (the request URI '
        . 'or CLI command line) and wall time, so a follow-up get_report_metadata '
        . 'call is usually unnecessary',
    )]
    public function findReports(
        #[Schema(
            description: 'Pattern matched case-insensitively against the request URI '
            . 'and CLI command line. Plain text matches as a substring; * ? and [] '
            . 'enable wildcard matching.',
        )]
        ?string $query = null,
        #[Schema(
            description: 'Only reports whose execution started within the last N seconds',
            type: 'integer',
            minimum: 0,
        )]
        ?int $within_last_seconds = null,
        #[Schema(
            description: 'Only reports whose execution started at or after this '
            . 'Unix time (seconds)',
            type: 'integer',
        )]
        ?int $since_timestamp = null,
        #[Schema(
            description: 'Only reports whose recorded wall time is >= this value '
            . '(same unit as wall_time_ms from get_report_metadata)',
            type: 'integer',
            minimum: 0,
        )]
        ?int $min_wall_time_ms = null,
        #[Schema(
            description: 'Maximum number of reports to return, most recent first',
            type: 'integer',
            minimum: 1,
            maximum: 200,
        )]
        int $limit = 50,
    ): array {
        $since = $since_timestamp;
        if ($within_last_seconds !== null) {
            $relativeSince = max(
                0,
                $this->clock->now()->getTimestamp() - $within_last_seconds,
            );
            $since = $since === null ? $relativeSince : max($since, $relativeSince);
        }

        return $this->reportStore->findReports(
            new ReportFilter(
                query: $query,
                since: $since,
                minWallTime: $min_wall_time_ms,
                limit: $limit,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_report_metadata',
        description: 'Get the metadata for a SPX profiling report '
        . '(enabled metrics, URL, duration, memory, etc.)',
    )]
    public function getReportMetadata(
        #[Schema(description: 'The report key')]
        string $report_key,
    ): array {
        return $this->reportStore->getReportMetadata($report_key);
    }

    /**
     * @param list<string> $root_stack
     * @return array{metric: string, root: CallTreeNode}
     */
    #[McpTool(
        name: 'get_aggregated_call_graph',
        description: 'Get the aggregated and pruned call graph for a SPX report',
    )]
    public function getAggregatedCallGraph(
        #[Schema(description: 'The report key')]
        string $report_key,
        #[Schema(
            description: 'Metric to use (e.g. wt, ct, zm)',
            enum: self::METRICS,
        )]
        string $metric = 'wt',
        #[Schema(
            description: 'Relative pruning threshold: nodes below this fraction '
            . 'of total metric are dropped',
            type: 'number',
            minimum: 0,
            maximum: 1,
        )]
        float  $pruning_relative_threshold = 0.005,
        #[Schema(
            description: 'Optional call path to zoom into, from the outermost frame '
            . 'inward. Each entry is a function name as shown in the graph (e.g. '
            . '"Composer\\\\Autoload\\\\ClassLoader::loadClass"). The result is '
            . 're-rooted on the call this path lands on, and pruning is relative '
            . 'to that subtree.',
            type: 'array',
            items: ['type' => 'string'],
        )]
        array $root_stack = [],
    ): array {
        return $this->callGraphAggregator->getAggregatedCallGraph(
            $report_key,
            $metric,
            $pruning_relative_threshold,
            $root_stack,
        );
    }

    /**
     * @return list<FlatProfileEntry>
     */
    #[McpTool(
        name: 'get_flat_profile',
        description: 'Get the flat profile for a SPX report: per-function metric '
        . 'totals aggregated across all call contexts, sorted by exclusive '
        . 'metric descending. Catches functions that are individually cheap but '
        . 'collectively expensive across many call sites, which the call graph '
        . 'spreads across separate nodes. Each entry reports call count plus '
        . 'exclusive and inclusive metric (absolute and relative).',
    )]
    public function getFlatProfile(
        #[Schema(description: 'The report key')]
        string $report_key,
        #[Schema(
            description: 'Metric to use (e.g. wt, ct, zm)',
            enum: self::METRICS,
        )]
        string $metric = 'wt',
        #[Schema(
            description: 'Maximum number of functions to return, most expensive first',
            type: 'integer',
            minimum: 1,
            maximum: 500,
        )]
        int $limit = 50,
    ): array {
        return $this->callGraphAggregator->getFlatProfile(
            $report_key,
            $metric,
            $limit,
        );
    }

    /**
     * @return array{metric: string, root: CallTreeNode}
     */
    #[McpTool(
        name: 'get_callers',
        description: 'Get the inverted (callers) call graph anchored on a function: '
        . 'starting from the given function, walk up through its callers to the '
        . 'entry point, attributing to each caller path the share of the function '
        . 'metric flowing through it. The bottom-up counterpart of '
        . 'get_aggregated_call_graph, used to find who is responsible for a '
        . 'function flagged by get_flat_profile.',
    )]
    public function getCallers(
        #[Schema(description: 'The report key')]
        string $report_key,
        #[Schema(
            description: 'Function to invert around, as shown in get_flat_profile or '
            . 'the call graph (e.g. "Composer\\\\Autoload\\\\ClassLoader::loadClass").',
        )]
        string $function,
        #[Schema(
            description: 'Metric to use (e.g. wt, ct, zm)',
            enum: self::METRICS,
        )]
        string $metric = 'wt',
        #[Schema(
            description: 'Relative pruning threshold: caller paths below this fraction '
            . 'of the function total metric are dropped',
            type: 'number',
            minimum: 0,
            maximum: 1,
        )]
        float $pruning_relative_threshold = 0.005,
    ): array {
        return $this->callGraphAggregator->getCallers(
            $report_key,
            $function,
            $metric,
            $pruning_relative_threshold,
        );
    }
}
