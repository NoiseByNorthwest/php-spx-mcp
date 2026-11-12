<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp\Tests;

use NoiseByNorthwest\SpxMcp\CallGraphAggregator;
use NoiseByNorthwest\SpxMcp\SpxReportStore;
use NoiseByNorthwest\SpxMcp\Tools\SpxToolProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CallGraphCacheTest extends TestCase
{
    private const GZ_REPORT_KEY = 'spx-full-20260605_200325-f4b095657445-4367-1804289383';

    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = __DIR__ . '/profiles';
    }

    public function testRepeatCallsReturnIdenticalResults(): void
    {
        $provider = self::makeProvider();

        $firstGraph = $provider->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.01,
        );
        $secondGraph = $provider->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.01,
        );

        self::assertSame($firstGraph, $secondGraph);

        $firstFlat = $provider->getFlatProfile(self::GZ_REPORT_KEY, 'wt', 50);
        $secondFlat = $provider->getFlatProfile(self::GZ_REPORT_KEY, 'wt', 50);

        self::assertSame($firstFlat, $secondFlat);
    }

    public function testPruningDoesNotLeakBetweenCalls(): void
    {
        $provider = self::makeProvider();

        $flatBefore = $provider->getFlatProfile(self::GZ_REPORT_KEY, 'wt', 50);

        $strict = $provider->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.5,
        );
        $loose = $provider->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.001,
        );

        self::assertGreaterThan(
            self::countNodes($strict['root']),
            self::countNodes($loose['root']),
            'a looser threshold after a strict call must still see pruned nodes',
        );

        $flatAfter = $provider->getFlatProfile(self::GZ_REPORT_KEY, 'wt', 50);

        self::assertSame($flatBefore, $flatAfter);
    }

    public function testCacheInvalidatesOnBodyRewrite(): void
    {
        $key = self::GZ_REPORT_KEY;
        $dir = sys_get_temp_dir() . '/spx-mcp-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            copy(self::$fixturesDir . '/' . $key . '.json', $dir . '/' . $key . '.json');
            copy(self::$fixturesDir . '/' . $key . '.txt.gz', $dir . '/' . $key . '.txt.gz');

            $store = new SpxReportStore($dir, new NullLogger());
            $provider = new SpxToolProvider(
                $store,
                new CallGraphAggregator($store),
            );

            $first = $provider->getAggregatedCallGraph($key, 'wt', 0.01);
            $firstFingerprint = $store->getReportFingerprint($key);

            $bodyPath = $dir . '/' . $key . '.txt.gz';
            $replacement = "[functions]\n/app/a.php:1:foo\n[events]\n0 1 1000\n0 0 1500\n";
            file_put_contents($bodyPath, gzencode($replacement));
            clearstatcache(true, $bodyPath);
            touch($bodyPath, time() + 10);
            clearstatcache(true, $bodyPath);

            $secondFingerprint = $store->getReportFingerprint($key);
            self::assertNotSame($firstFingerprint, $secondFingerprint);

            $second = $provider->getAggregatedCallGraph($key, 'wt', 0.01);
            self::assertNotEquals($first, $second);
            self::assertSame('foo', $second['root']['children'][0]['name']);
        } finally {
            @unlink($dir . '/' . $key . '.json');
            @unlink($dir . '/' . $key . '.txt.gz');
            @rmdir($dir);
        }
    }

    private static function makeProvider(?string $dataDir = null): SpxToolProvider
    {
        $store = new SpxReportStore($dataDir ?? self::$fixturesDir, new NullLogger());

        return new SpxToolProvider($store, new CallGraphAggregator($store));
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private static function countNodes(array $node): int
    {
        $count = 0;
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return 0;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $count += 1 + self::countNodes($child);
            }
        }

        return $count;
    }
}
