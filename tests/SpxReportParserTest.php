<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp\Tests;

use NoiseByNorthwest\SpxMcp\SpxReportParser;
use PHPUnit\Framework\TestCase;

class SpxReportParserTest extends TestCase
{
    public function testParseFunctionLineSplitsFileLineAndName(): void
    {
        $reportParser = new SpxReportParser();

        self::assertSame(
            [
                'functionName' => 'Foo\\Bar::baz',
                'file' => '/app/src/Foo.php',
                'lineNumber' => 42,
            ],
            $reportParser->parseFunctionLine('/app/src/Foo.php:42:Foo\\Bar::baz'),
        );
    }

    public function testParseFunctionLineKeepsColonsInsideFunctionName(): void
    {
        $reportParser = new SpxReportParser();

        $row = $reportParser->parseFunctionLine(
            'phar:0:Some::method:weird:name',
        );

        self::assertSame('Some::method:weird:name', $row['functionName']);
    }

    public function testParseFunctionLineQualifiesAnonymousClosureWithItsLocation(): void
    {
        $reportParser = new SpxReportParser();

        $row = $reportParser->parseFunctionLine(
            '/app/src/Foo.php:575:{closure}',
        );

        self::assertSame(
            '{closure:/app/src/Foo.php:575}',
            $row['functionName'],
        );
    }

    public function testParseFunctionLineSplitsPharUrlFileFromLineAndName(): void
    {
        $reportParser = new SpxReportParser();

        // The "phar://" file itself contains colons; the line number is the pivot,
        // so file and name must keep their own colons intact.
        self::assertSame(
            [
                'functionName' => 'Composer\\Console\\Application::run',
                'file'         => 'phar:///usr/local/bin/composer/src/Composer/Console/'
                    . 'Application.php',
                'lineNumber'   => 133,
            ],
            $reportParser->parseFunctionLine(
                'phar:///usr/local/bin/composer/src/Composer/Console/Application.php'
                . ':133:Composer\\Console\\Application::run',
            ),
        );
    }

    public function testParseFunctionLineKeepsPharUrlOnBothFileAndName(): void
    {
        $reportParser = new SpxReportParser();

        // The file-as-function entry repeats the phar URL on both sides of the line.
        $row = $reportParser->parseFunctionLine(
            'phar:///usr/local/bin/composer/bin/composer'
            . ':1:phar:///usr/local/bin/composer/bin/composer',
        );

        self::assertSame(
            'phar:///usr/local/bin/composer/bin/composer',
            $row['file'],
        );
        self::assertSame(1, $row['lineNumber']);
        self::assertSame(
            'phar:///usr/local/bin/composer/bin/composer',
            $row['functionName'],
        );
    }

    public function testParseEventLineReadsLegacySpaceSeparatedTuple(): void
    {
        $reportParser = new SpxReportParser();

        self::assertSame(
            [5, 1, 100, 200],
            $reportParser->parseEventLine('5 1 100 200'),
        );
    }

    public function testParseEventLineReadsCompressedStartEvent(): void
    {
        $reportParser = new SpxReportParser();

        // "callSite|fnIdx|m1|m2", call-site is ignored
        self::assertSame(
            [5, 1, 100, 200],
            $reportParser->parseEventLine('1a2b|5|100|200'),
        );
    }

    public function testParseEventLineReadsCompressedEndEvent(): void
    {
        $reportParser = new SpxReportParser();

        // a leading '-' marks the end of the call to fnIdx, with no call-site column
        self::assertSame(
            [5, 0, 100],
            $reportParser->parseEventLine('-5|100'),
        );
    }

    public function testParseEventLineTreatsBareMetricAsDeltaFromPreviousEvent(): void
    {
        $reportParser = new SpxReportParser();

        $reportParser->parseEventLine('1a2b|5|100');
        // bare "30" means +30 relative to the previous event's metric
        self::assertSame(
            [7, 1, 130],
            $reportParser->parseEventLine('1a2b|7|30'),
        );
    }

    public function testParseEventLineTreatsAPrefixedMetricAsAbsolute(): void
    {
        $reportParser = new SpxReportParser();

        $reportParser->parseEventLine('1a2b|5|100');
        // "a40" overrides the delta accumulation with an absolute value
        self::assertSame(
            [7, 1, 40],
            $reportParser->parseEventLine('1a2b|7|a40'),
        );
    }

    public function testParseEventLineTreatsEmptyMetricAsZeroDelta(): void
    {
        $reportParser = new SpxReportParser();

        $reportParser->parseEventLine('1a2b|5|100');
        // an empty metric column carries over the previous value unchanged
        self::assertSame(
            [7, 1, 100],
            $reportParser->parseEventLine('1a2b|7|'),
        );
    }

    public function testParseEventLineResolvesFnIdxBackReference(): void
    {
        $reportParser = new SpxReportParser();

        $reportParser->parseEventLine('1a2b|5|100');
        // "r1" reuses the fnIdx of the most recent event; the absolute "a200"
        // keeps the metric out of the way so the assertion is about the fnIdx
        self::assertSame(
            [5, 1, 200],
            $reportParser->parseEventLine('1a2b|r1|a200'),
        );
    }

    public function testParseEventLineRejectsBackReferenceOutsideWindow(): void
    {
        $reportParser = new SpxReportParser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/back-ref .r1. points outside the 0-event window/',
        );

        $reportParser->parseEventLine('1a2b|r1|100');
    }
}
