<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

use Psr\Log\LoggerInterface;

/**
 * Handles all filesystem access to SPX reports: discovery, metadata, and streaming
 * of the (possibly compressed) report body. Callers get plain data (keys, metadata
 * arrays, lines) and don't deal with file handles or paths.
 *
 * @phpstan-type ReportSummary array{
 *     key: string,
 *     timestamp: int,
 *     descriptor: string,
 *     wall_time_ms: int,
 * }
 */
class SpxReportStore
{
    public function __construct(
        private readonly string $dataDir,
        private readonly LoggerInterface $logger = new StderrLogger(),
    ) {}

    /**
     * Returns the essential metadata of the reports matching $filter, most recent
     * first. A report whose metadata cannot be read is skipped (and logged) rather
     * than aborting the whole listing.
     *
     * @return list<ReportSummary>
     */
    public function findReports(ReportFilter $filter): array
    {
        $summaries = [];
        foreach ($this->discoverReportKeys() as $key) {
            try {
                $metadata = $this->getReportMetadata($key);
            } catch (\RuntimeException $e) {
                $this->logger->warning(sprintf(
                    "skipping unreadable report '%s': %s",
                    $key,
                    $e->getMessage(),
                ));

                continue;
            }

            $timestamp = self::intOrZero($metadata['exec_ts'] ?? null);
            $wallTime = self::intOrZero($metadata['wall_time_ms'] ?? null);
            $descriptors = self::reportDescriptors($metadata);

            if ($filter->since !== null && $timestamp < $filter->since) {
                continue;
            }

            if ($filter->minWallTime !== null && $wallTime < $filter->minWallTime) {
                continue;
            }

            if ($filter->query !== null && !self::matchesAny($filter->query, $descriptors)) {
                continue;
            }

            $summaries[] = [
                'key'          => $key,
                'timestamp'    => $timestamp,
                'descriptor'   => $descriptors[0] ?? '',
                'wall_time_ms' => $wallTime,
            ];
        }

        usort(
            $summaries,
            fn(array $left, array $right): int => $right['timestamp'] <=> $left['timestamp'],
        );

        return array_slice($summaries, 0, max(1, $filter->limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function getReportMetadata(string $reportKey): array
    {
        $this->validateReportKey($reportKey);
        $path = $this->dataDir . '/' . $reportKey . '.json';
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException(
                "Cannot read metadata for report '$reportKey'",
            );
        }

        /** @var array<string, mixed>|null $metadata */
        $metadata = json_decode($json, true);
        if (!is_array($metadata)) {
            throw new \RuntimeException(
                "Malformed metadata for report '$reportKey'",
            );
        }

        return $metadata;
    }

    /**
     * Returns the ordered list of metrics recorded in a report. The position of a
     * metric in this list is its column offset within each event tuple, so callers
     * resolving a metric to event data must preserve the ordering.
     *
     * @return list<string>
     */
    public function getEnabledMetrics(string $reportKey): array
    {
        $metadata = $this->getReportMetadata($reportKey);

        $enabledMetrics = $metadata['enabled_metrics'] ?? null;
        if (!is_array($enabledMetrics) || !array_is_list($enabledMetrics)) {
            throw new \RuntimeException(
                "Report metadata is missing 'enabled_metrics'",
            );
        }

        foreach ($enabledMetrics as $enabledMetric) {
            if (!is_string($enabledMetric)) {
                throw new \RuntimeException(
                    "Report metadata 'enabled_metrics' must be a list of strings",
                );
            }
        }

        /** @var list<string> $enabledMetrics */
        return $enabledMetrics;
    }

    /**
     * Streams a report body line by line, decompressing .txt.gz or .txt.zst and
     * trimming the trailing newline. The handle is closed when iteration ends or
     * the generator is abandoned.
     *
     * @return \Generator<int, string>
     */
    public function streamReportLines(string $key): \Generator
    {
        $handle = $this->openReportHandle($key);
        try {
            while (($line = fgets($handle)) !== false) {
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($handle);
        }
    }

    private static function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The strings a report can be searched by: its HTTP request URI and/or CLI
     * command line, skipping the 'n/a' placeholders SPX writes for the unused side.
     *
     * @param array<string, mixed> $metadata
     * @return list<string>
     */
    private static function reportDescriptors(array $metadata): array
    {
        $descriptors = [];
        foreach (['http_request_uri', 'cli_command_line'] as $field) {
            $value = $metadata[$field] ?? null;
            if (is_string($value) && $value !== '' && $value !== 'n/a') {
                $descriptors[] = $value;
            }
        }

        return $descriptors;
    }

    /**
     * Case-insensitive match: a pattern containing a wildcard (* ? or [) is matched
     * with fnmatch(), otherwise it is treated as a plain substring.
     *
     * @param list<string> $haystacks
     */
    private static function matchesAny(string $pattern, array $haystacks): bool
    {
        $isWildcard = strpbrk($pattern, '*?[') !== false;
        foreach ($haystacks as $haystack) {
            if ($isWildcard) {
                if (fnmatch($pattern, $haystack, FNM_CASEFOLD)) {
                    return true;
                }
            } elseif (stripos($haystack, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function discoverReportKeys(): array
    {
        $reportKeys = [];
        $metadataFiles = glob($this->dataDir . '/*.json');
        foreach ($metadataFiles !== false ? $metadataFiles : [] as $metadataFileName) {
            $key = basename($metadataFileName, '.json');
            $base = $this->dataDir . '/' . $key;
            if (file_exists($base . '.txt.gz') || file_exists($base . '.txt.zst')) {
                $reportKeys[] = $key;
            }
        }

        return $reportKeys;
    }

    /**
     * Reports are user-controlled identifiers that we concatenate into a
     * filesystem path. Allowing slashes or '..' would expose any readable
     * .json/.txt.gz/.txt.zst on disk via the MCP interface.
     */
    private function validateReportKey(string $reportKey): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $reportKey) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid report key: must match [A-Za-z0-9._-]+",
            );
        }
    }

    /**
     * @return resource
     */
    private function openReportHandle(string $key): mixed
    {
        $this->validateReportKey($key);
        $base = $this->dataDir . '/' . $key;

        $gzPath = $base . '.txt.gz';
        $zstPath = $base . '.txt.zst';

        if (file_exists($gzPath)) {
            $handle = fopen('compress.zlib://' . $gzPath, 'r');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open report file: $gzPath");
            }

            return $handle;
        }

        if (file_exists($zstPath)) {
            if (!extension_loaded('zstd')) {
                throw new \RuntimeException(
                    "PHP extension 'zstd' is required to read .txt.zst report files",
                );
            }

            $handle = fopen('compress.zstd://' . $zstPath, 'r');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open report file: $zstPath");
            }

            return $handle;
        }

        throw new \RuntimeException(
            "Report file not found for key: $key (tried .txt.gz and .txt.zst)",
        );
    }
}
