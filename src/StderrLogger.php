<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Stdout carries the MCP protocol on stdio transports, so log output must go to
 * STDERR instead.
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levelStr = is_string($level) || $level instanceof Stringable ? (string) $level : 'unknown';
        $line = sprintf('[%s] %s', strtoupper($levelStr), (string) $message);
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' ' . ($encoded !== false ? $encoded : '<unencodable context>');
        }
        fwrite(STDERR, $line . "\n");
    }
}
