<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

/**
 * Parses the two on-wire SPX report line formats into normalized integer tuples.
 *
 * Stateful: the back-reference window only spans events within a single report,
 * so a fresh parser must be used per file.
 *
 * @phpstan-type FunctionRow array{functionName: string, file: string, lineNumber: int}
 */
final class SpxReportParser
{
    private const BACK_REF_WINDOW = 20;

    /** @var list<list<int>> recent event tuples, most-recent first, capped at BACK_REF_WINDOW */
    private array $recentEvents = [];

    /**
     * @return FunctionRow
     */
    public function parseFunctionLine(string $line): array
    {
        // Format: "file:lineNumber:functionName". Both the file (e.g. a "phar://"
        // URL) and the functionName (e.g. "Foo::bar") may contain colons, so we
        // cannot blindly take the first/last segments. We anchor on lineNumber,
        // the first purely-numeric ':'-delimited segment, treating everything
        // before it as the file and everything after it as the name. This assumes
        // no file-path component is itself a bare integer between two colons, which
        // holds for real paths and "scheme://" URLs.
        $parts = explode(':', $line);

        $lineNumberIdx = null;
        foreach ($parts as $idx => $part) {
            if ($part !== '' && ctype_digit($part)) {
                $lineNumberIdx = $idx;

                break;
            }
        }

        if ($lineNumberIdx === null) {
            $file = '';
            $lineNumber = 0;
            $functionName = $line;
        } else {
            $file = implode(':', array_slice($parts, 0, $lineNumberIdx));
            $lineNumber = (int) $parts[$lineNumberIdx];
            $functionName = implode(':', array_slice($parts, $lineNumberIdx + 1));
        }

        if ($functionName === '{closure}') {
            $functionName = "{closure:$file:$lineNumber}";
        }

        return compact('functionName', 'file', 'lineNumber');
    }

    /**
     * Parses one event line. Two on-wire formats, never mixed within the same
     * file, so the legacy branch can return early without updating the back-ref
     * window (back-refs only exist in the compressed format).
     *
     *   Legacy (space-separated): "fnIdx start m1 m2 ..."
     *
     *   Compressed (pipe-separated):
     *     - "-" prefix marks a call END; otherwise START
     *     - START: "callSiteLine(hex)|fnIdx|m1|m2|..."
     *     - END:   "-fnIdx|m1|m2|..."
     *     - "rN" on fnIdx = back-ref to N-th most recent event's fnIdx
     *     - "aN" on metric = absolute; bare "N" = delta from last event; "" = 0 delta
     *
     * The call-site (START events) is ignored; no consumer uses it currently.
     *
     * Returned tuple: [fnIdx, start(1/0), m1, m2, ...]
     *
     * @return list<int>
     */
    public function parseEventLine(string $line): array
    {
        if (str_contains($line, ' ')) {
            return array_map('intval', explode(' ', $line));
        }

        $start = $line[0] !== '-';
        $parts = explode('|', $line);
        // In compressed format, parts[0] is the call-site (hex) for START events
        // and "-fnIdx" for END events. Skip parts[0] when looping metrics on START.
        $callSiteOffset = $start ? 1 : 0;

        $event = array_fill(0, count($parts), 0);
        $event[1] = $start ? 1 : 0;

        for ($i = $callSiteOffset, $n = count($parts); $i < $n; $i++) {
            $v = $parts[$i];

            if ($i === $callSiteOffset) {
                if (!$start) {
                    $v = substr($v, 1); // strip leading '-'
                }

                if ($v !== '' && $v[0] === 'r') {
                    $backRefIdx = (int) substr($v, 1) - 1;
                    if (!isset($this->recentEvents[$backRefIdx])) {
                        throw new \RuntimeException(sprintf(
                            "Malformed event line: back-ref 'r%d' points outside"
                            . ' the %d-event window',
                            $backRefIdx + 1,
                            count($this->recentEvents),
                        ));
                    }

                    $event[0] = $this->recentEvents[$backRefIdx][0];
                } else {
                    $event[0] = (int) $v;
                }
            } else {
                $metricColumn = $i - $callSiteOffset + 1; // position in event tuple (always >= 2)

                if ($v === '') {
                    $v = '0';
                }

                if ($v[0] === 'a') {
                    $event[$metricColumn] = (int) substr($v, 1);
                } else {
                    $prev = $this->recentEvents !== []
                        ? ($this->recentEvents[0][$metricColumn] ?? 0)
                        : 0;
                    $event[$metricColumn] = (int) $v + $prev;
                }
            }
        }

        // array_fill + sparse writes leave PHPStan unsure that $event is a list;
        // coerce explicitly so the @return list<int> contract is provable.
        $event = array_values($event);

        array_unshift($this->recentEvents, $event);
        if (count($this->recentEvents) > self::BACK_REF_WINDOW) {
            array_pop($this->recentEvents);
        }

        return $event;
    }
}
