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
 * @phpstan-import-type ReportSummary from SpxReportStore
 */
class SpxToolProvider
{
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
            $relativeSince = max(0, $this->clock->now()->getTimestamp() - $within_last_seconds);
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
     * The metric enum below is the static set of metrics SPX can emit, advertised
     * to the client for input validation. It is intentionally distinct from the
     * per-report validation in {@see CallGraphAggregator::getAggregatedCallGraph()},
     * which rejects metrics not actually recorded in the targeted report.
     *
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
            enum: [
                'wt', 'ct', 'zm', 'zmmu', 'zmc', 'ze', 'zr', 'zo', 'zgc', 'zgr',
                'io', 'ior', 'iow',
            ],
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
                . 'inward. Each entry is a function name as shown in the graph, '
                . 'optionally prefixed with "<lineNumber>:" to disambiguate (e.g. '
                . '"423:Composer\\\\Autoload\\\\ClassLoader::loadClass"). The result is '
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
}
