<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

/**
 * Server-side filter for {@see SpxReportStore::findReports()}. All criteria are
 * optional and combined with AND. $since is an absolute Unix timestamp compared
 * against each report's execution start (exec_ts); relative windows (e.g. "within
 * the last N seconds") are resolved to it at the tool boundary so the store stays
 * free of any clock dependency.
 */
final class ReportFilter
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly ?int $since = null,
        public readonly ?int $minWallTime = null,
        public readonly int $limit = 50,
    ) {}
}
