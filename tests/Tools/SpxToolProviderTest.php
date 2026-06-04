<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp\Tests\Tools;

use NoiseByNorthwest\SpxMcp\CallGraphAggregator;
use NoiseByNorthwest\SpxMcp\SpxReportStore;
use NoiseByNorthwest\SpxMcp\SystemClock;
use NoiseByNorthwest\SpxMcp\Tools\SpxToolProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type CallTreeNode from CallGraphAggregator
 * @phpstan-import-type FlatProfileEntry from CallGraphAggregator
 */
class SpxToolProviderTest extends TestCase
{
    private const GZ_REPORT_KEY = 'spx-full-20260525_215443-5cba1aa08353-4365-1804289383';
    private const ZST_REPORT_KEY = 'spx-full-20260525_220721-12ff3135c867-4437-1804289383';

    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = dirname(__DIR__) . '/profiles';
    }

    public function testFindReportsReturnsEssentialMetadataMostRecentFirst(): void
    {
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
                [
                    'key'          => self::GZ_REPORT_KEY,
                    'timestamp'    => 1779746083,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1096065,
                ],
            ],
            self::makeProvider()->findReports(),
        );
    }

    public function testFindReportsMatchesQueryAsSubstringAcrossUrlAndCommand(): void
    {
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
                [
                    'key'          => self::GZ_REPORT_KEY,
                    'timestamp'    => 1779746083,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1096065,
                ],
            ],
            self::makeProvider()->findReports(query: 'composer install'),
        );
    }

    public function testFindReportsMatchesQueryWithWildcard(): void
    {
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
                [
                    'key'          => self::GZ_REPORT_KEY,
                    'timestamp'    => 1779746083,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1096065,
                ],
            ],
            self::makeProvider()->findReports(query: '*--no-progress'),
        );
    }

    public function testFindReportsReturnsEmptyWhenQueryMatchesNothing(): void
    {
        self::assertSame(
            [],
            self::makeProvider()->findReports(query: 'nonexistent-route'),
        );
    }

    public function testFindReportsFiltersBySinceTimestamp(): void
    {
        // 1779746500 sits between GZ (@1779746083) and ZST (@1779746841)
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
            ],
            self::makeProvider()->findReports(since_timestamp: 1779746500),
        );
    }

    public function testFindReportsFiltersByMinWallTime(): void
    {
        // 1100000 sits between GZ (1096065) and ZST (1116321)
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
            ],
            self::makeProvider()->findReports(min_wall_time_ms: 1100000),
        );
    }

    public function testFindReportsRespectsLimit(): void
    {
        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
            ],
            self::makeProvider()->findReports(limit: 1),
        );
    }

    public function testFindReportsResolvesWithinLastSecondsAgainstInjectedClock(): void
    {
        // freeze "now" 100s after the most recent report (ZST @1779746841)
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1779746941');
            }
        };
        $toolProvider = self::makeProvider(clock: $clock);

        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
            ],
            $toolProvider->findReports(within_last_seconds: 200),
        );

        self::assertSame(
            [
                [
                    'key'          => self::ZST_REPORT_KEY,
                    'timestamp'    => 1779746841,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1116321,
                ],
                [
                    'key'          => self::GZ_REPORT_KEY,
                    'timestamp'    => 1779746083,
                    'descriptor'   => '/usr/local/bin/composer install '
                        . '--no-interaction --no-progress',
                    'wall_time_ms' => 1096065,
                ],
            ],
            $toolProvider->findReports(within_last_seconds: 1000),
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function findReportsReturnsEmptyWhenNoCompleteReportDataProvider(): array
    {
        return [
            'empty directory'          => [[]],
            'json without report file' => [['orphan.json' => '{}']],
            'unreadable metadata'      => [
                ['broken.json' => 'not valid json', 'broken.txt.gz' => ''],
            ],
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('findReportsReturnsEmptyWhenNoCompleteReportDataProvider')]
    public function testFindReportsReturnsEmptyWhenNoCompleteReport(array $files): void
    {
        self::withTempDir($files, function (string $dir): void {
            self::assertSame([], self::makeProvider($dir)->findReports());
        });
    }

    public function testGetReportMetadataReturnsFullSidecarJson(): void
    {
        $metadata = self::makeProvider()->getReportMetadata(
            self::GZ_REPORT_KEY,
        );

        self::assertSame(
            [
                'key'                   => self::GZ_REPORT_KEY,
                'exec_ts'               => 1779746083,
                'host_name'             => '5cba1aa08353',
                'process_pid'           => 4365,
                'process_tid'           => 0,
                'process_pwd'           => '/app',
                'cli'                   => 1,
                'cli_command_line'      => '/usr/local/bin/composer install'
                    . ' --no-interaction --no-progress',
                'http_request_uri'      => 'n/a',
                'http_method'           => 'n/a',
                'http_host'             => 'n/a',
                'custom_metadata_str'   => null,
                'wall_time_ms'          => 1096065,
                'peak_memory_usage'     => 16297632,
                'called_function_count' => 1713,
                'call_count'            => 463766,
                'recorded_call_count'   => 463766,
                'enabled_metrics'       => ['wt', 'zm'],
            ],
            $metadata,
        );
    }

    /**
     * @return array<string, array{string, class-string<\Throwable>, string}>
     */
    public static function getReportMetadataRejectsInvalidKeyDataProvider(): array
    {
        return [
            'path traversal' => [
                '../etc/passwd',
                \InvalidArgumentException::class,
                '/Invalid report key/',
            ],
            'slash in key' => [
                'sub/dir', \InvalidArgumentException::class, '/Invalid report key/',
            ],
            'unknown report' => [
                'does-not-exist', \RuntimeException::class, '/Cannot read metadata/',
            ],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('getReportMetadataRejectsInvalidKeyDataProvider')]
    public function testGetReportMetadataRejectsInvalidKey(
        string $key,
        string $exception,
        string $messagePattern,
    ): void {
        $this->expectException($exception);
        $this->expectExceptionMessageMatches($messagePattern);

        self::makeProvider()->getReportMetadata($key);
    }

    /**
     * @return array<string, array{
     *     string,
     *     float,
     *     array{metric: string, root: CallTreeNode},
     * }>
     */
    public static function getAggregatedCallGraphResultDataProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength -- wide, deeply-nested call-graph fixture literals
        return [
            'gz, thr = 0.0005' => [
                self::GZ_REPORT_KEY,
                0.0005,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => 'root',
                        'value' => 0,
                        'calls' => 0,
                        'children' => [
                            [
                                'name' => '/usr/local/bin/composer',
                                'value' => 1095197049,
                                'calls' => 1,
                                'file' => '/usr/local/bin/composer',
                                'lineNumber' => 1,
                                'children' => [
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'value' => 1086894502,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'lineNumber' => 1,
                                        'children' => [
                                            [
                                                'name' => 'Composer\\Console\\Application::run',
                                                'value' => 1083510082,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                                'lineNumber' => 133,
                                                'children' => [
                                                    [
                                                        'name' => 'Symfony\\Component\\Console\\Application::run',
                                                        'value' => 1082553317,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/symfony/console/Application.php',
                                                        'lineNumber' => 137,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'name' => 'Composer\\Autoload\\ClassLoader::loadClass',
                                                'value' => 1668484,
                                                'calls' => 4,
                                                'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                'lineNumber' => 423,
                                                'children' => [
                                                    [
                                                        'name' => '{closure:Composer\\Autoload\\ClassLoader::initializeIncludeClosure():575}',
                                                        'value' => 1650362,
                                                        'calls' => 4,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                        'lineNumber' => 575,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'name' => 'phar:///usr/local/bin/composer/src/bootstrap.php',
                                                'value' => 1064426,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/src/bootstrap.php',
                                                'lineNumber' => 1,
                                                'children' => [
                                                    [
                                                        'name' => 'includeIfExists',
                                                        'value' => 1061184,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/src/bootstrap.php',
                                                        'lineNumber' => 15,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'name' => 'Phar::mapPhar',
                                        'value' => 8160792,
                                        'calls' => 1,
                                        'file' => '',
                                        'lineNumber' => 0,
                                        'children' => [],
                                    ],
                                ],
                            ],
                            [
                                'name' => '::zend_compile_file',
                                'value' => 701466,
                                'calls' => 1,
                                'file' => '',
                                'lineNumber' => 0,
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'gz, thr = 0.001' => [
                self::GZ_REPORT_KEY,
                0.001,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => 'root',
                        'value' => 0,
                        'calls' => 0,
                        'children' => [
                            [
                                'name' => '/usr/local/bin/composer',
                                'value' => 1095197049,
                                'calls' => 1,
                                'file' => '/usr/local/bin/composer',
                                'lineNumber' => 1,
                                'children' => [
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'value' => 1086894502,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'lineNumber' => 1,
                                        'children' => [
                                            [
                                                'name' => 'Composer\\Console\\Application::run',
                                                'value' => 1083510082,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                                'lineNumber' => 133,
                                                'children' => [
                                                    [
                                                        'name' => 'Symfony\\Component\\Console\\Application::run',
                                                        'value' => 1082553317,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/symfony/console/Application.php',
                                                        'lineNumber' => 137,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'name' => 'Composer\\Autoload\\ClassLoader::loadClass',
                                                'value' => 1668484,
                                                'calls' => 4,
                                                'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                'lineNumber' => 423,
                                                'children' => [
                                                    [
                                                        'name' => '{closure:Composer\\Autoload\\ClassLoader::initializeIncludeClosure():575}',
                                                        'value' => 1650362,
                                                        'calls' => 4,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                        'lineNumber' => 575,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'name' => 'Phar::mapPhar',
                                        'value' => 8160792,
                                        'calls' => 1,
                                        'file' => '',
                                        'lineNumber' => 0,
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'gz, thr = 0.01' => [
                self::GZ_REPORT_KEY,
                0.01,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => 'root',
                        'value' => 0,
                        'calls' => 0,
                        'children' => [
                            [
                                'name' => '/usr/local/bin/composer',
                                'value' => 1095197049,
                                'calls' => 1,
                                'file' => '/usr/local/bin/composer',
                                'lineNumber' => 1,
                                'children' => [
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'value' => 1086894502,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'lineNumber' => 1,
                                        'children' => [
                                            [
                                                'name' => 'Composer\\Console\\Application::run',
                                                'value' => 1083510082,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                                'lineNumber' => 133,
                                                'children' => [
                                                    [
                                                        'name' => 'Symfony\\Component\\Console\\Application::run',
                                                        'value' => 1082553317,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/symfony/console/Application.php',
                                                        'lineNumber' => 137,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'zst, thr = 0.01' => [
                self::ZST_REPORT_KEY,
                0.01,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => 'root',
                        'value' => 0,
                        'calls' => 0,
                        'children' => [
                            [
                                'name' => '/usr/local/bin/composer',
                                'value' => 1115549512,
                                'calls' => 1,
                                'file' => '/usr/local/bin/composer',
                                'lineNumber' => 1,
                                'children' => [
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'value' => 1109671897,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'lineNumber' => 1,
                                        'children' => [
                                            [
                                                'name' => 'Composer\\Console\\Application::run',
                                                'value' => 1106734803,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                                'lineNumber' => 133,
                                                'children' => [
                                                    [
                                                        'name' => 'Symfony\\Component\\Console\\Application::run',
                                                        'value' => 1105907526,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/symfony/console/Application.php',
                                                        'lineNumber' => 137,
                                                        'children' => [],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    /**
     * @param array{metric: string, root: CallTreeNode} $expectedResult
     */
    #[DataProvider('getAggregatedCallGraphResultDataProvider')]
    public function testGetAggregatedCallGraphResult(
        string $reportKey,
        float $pruningRelativeThreshold,
        array $expectedResult,
    ): void {
        $result = self::makeProvider()->getAggregatedCallGraph(
            $reportKey,
            'wt',
            $pruningRelativeThreshold,
        );
        $result['root'] = self::truncateToDepth($result['root'], 4);

        self::assertSame($expectedResult, $result);
    }

    /**
     * @return array<string, array{
     *     string,
     *     float,
     *     list<string>,
     *     array{metric: string, root: CallTreeNode},
     * }>
     */
    public static function getAggregatedCallGraphResultWithCustomRootStackDataProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength -- wide, deeply-nested call-graph fixture literals
        return [
            'gz, thr = 0.01' => [
                self::GZ_REPORT_KEY,
                0.01,
                [
                    '/usr/local/bin/composer',
                    'phar:///usr/local/bin/composer/bin/composer',
                    'Composer\Autoload\ClassLoader::loadClass',
                ],
                [
                    'metric' => 'wt',
                    'root' =>  [
                        'name' => 'Composer\\Autoload\\ClassLoader::loadClass',
                        'value' => 1668484,
                        'calls' => 4,
                        'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                        'lineNumber' => 423,
                        'children' =>  [
                            [
                                'name' => '{closure:Composer\\Autoload\\ClassLoader::initializeIncludeClosure():575}',
                                'value' => 1650362,
                                'calls' => 4,
                                'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                'lineNumber' => 575,
                                'children' =>  [
                                    [
                                        'name' => '::zend_compile_file',
                                        'value' => 995665,
                                        'calls' => 4,
                                        'file' => '',
                                        'lineNumber' => 0,
                                        'children' =>  [],
                                    ],
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                        'value' => 648741,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                        'lineNumber' => 1,
                                        'children' =>  [
                                            [
                                                'name' => 'Composer\\Autoload\\ClassLoader::loadClass',
                                                'value' => 645249,
                                                'calls' => 1,
                                                'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                'lineNumber' => 423,
                                                'children' =>  [
                                                    [
                                                        'name' => '{closure:Composer\\Autoload\\ClassLoader::initializeIncludeClosure():575}',
                                                        'value' => 642385,
                                                        'calls' => 1,
                                                        'file' => 'phar:///usr/local/bin/composer/vendor/composer/ClassLoader.php',
                                                        'lineNumber' => 575,
                                                        'children' =>  [],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    /**
     * @param list<string> $rootStack
     * @param array{metric: string, root: CallTreeNode} $expectedResult
     */
    #[DataProvider('getAggregatedCallGraphResultWithCustomRootStackDataProvider')]
    public function testGetAggregatedCallGraphResultWithCustomRootStack(
        string $reportKey,
        float $pruningRelativeThreshold,
        array $rootStack,
        array $expectedResult,
    ): void {
        $result = self::makeProvider()->getAggregatedCallGraph(
            $reportKey,
            'wt',
            $pruningRelativeThreshold,
            $rootStack,
        );
        $result['root'] = self::truncateToDepth($result['root'], 4);

        self::assertSame($expectedResult, $result);
    }

    public function testGetAggregatedCallGraphIncludesSourceLocationForEveryFunction(): void
    {
        $result = self::makeProvider()->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.0,
        );

        $collectUnresolvedNames = function (
            array $node,
            bool $isRoot,
        ) use (&$collectUnresolvedNames): array {
            $unresolved = [];
            if (!$isRoot && !array_key_exists('file', $node)) {
                $name = $node['name'] ?? '';
                $unresolved[] = is_string($name) ? $name : '';
            }

            $children = $node['children'] ?? [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $unresolved = array_merge(
                            $unresolved,
                            $collectUnresolvedNames($child, false),
                        );
                    }
                }
            }

            return $unresolved;
        };

        self::assertSame(
            [],
            $collectUnresolvedNames($result['root'], true),
            'every non-root node in the call graph should carry its source location',
        );
    }

    public function testGetAggregatedCallGraphSortsChildrenByValueDesc(): void
    {
        $result = self::makeProvider()->getAggregatedCallGraph(
            self::GZ_REPORT_KEY,
            'wt',
            0.0,
        );
        $values = array_column($result['root']['children'], 'value');

        $sorted = $values;
        rsort($sorted);

        self::assertSame($sorted, $values);
    }

    public function testGetAggregatedCallGraphPruningIsMonotonicInThreshold(): void
    {
        $toolProvider = self::makeProvider();

        $countNodes = function (array $tree) use (&$countNodes): int {
            $children = $tree['children'] ?? [];
            if (!is_array($children)) {
                return 0;
            }

            $n = 0;
            foreach ($children as $child) {
                if (is_array($child)) {
                    $n += 1 + $countNodes($child);
                }
            }

            return $n;
        };

        [$loose, $strict, $maxed] = array_map(
            fn(float $t): int => $countNodes(
                $toolProvider->getAggregatedCallGraph(
                    self::GZ_REPORT_KEY,
                    'wt',
                    $t,
                )['root'],
            ),
            [0.0, 0.5, 1.0],
        );

        self::assertGreaterThan(
            0,
            $loose,
            'fixture should yield a non-empty tree at threshold 0',
        );
        self::assertGreaterThanOrEqual($strict, $loose);
        self::assertGreaterThanOrEqual($maxed, $strict);
    }

    /**
     * @return array<string, array{string, string, class-string<\Throwable>, string}>
     */
    public static function getAggregatedCallGraphRejectsInvalidArgsDataProvider(): array
    {
        return [
            'path traversal in key' => [
                '../etc/passwd', 'wt',
                \InvalidArgumentException::class, '/Invalid report key/',
            ],
            'unknown report' => [
                'does-not-exist', 'wt',
                \RuntimeException::class, '/Cannot read metadata/',
            ],
            'metric not enabled in report' => [
                self::GZ_REPORT_KEY, 'ct',
                \InvalidArgumentException::class, "/Metric 'ct' not recorded/",
            ],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('getAggregatedCallGraphRejectsInvalidArgsDataProvider')]
    public function testGetAggregatedCallGraphRejectsInvalidArgs(
        string $reportKey,
        string $metric,
        string $exception,
        string $messagePattern,
    ): void {
        $this->expectException($exception);
        $this->expectExceptionMessageMatches($messagePattern);

        self::makeProvider()->getAggregatedCallGraph(
            $reportKey,
            $metric,
            0.0,
        );
    }

    public function testGetAggregatedCallGraphThrowsWhenMetadataMissingEnabledMetrics(): void
    {
        $files = ['r.json' => (string) json_encode(['key' => 'r'])];
        self::withTempDir($files, function (string $dir): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches("/missing 'enabled_metrics'/");

            self::makeProvider($dir)->getAggregatedCallGraph('r', 'wt', 0.0);
        });
    }

    /**
     * @return array<string, array{string, int, list<FlatProfileEntry>}>
     */
    public static function getFlatProfileResultDataProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength -- wide flat-profile fixture literals
        return [
            'gz, wt, limit 5' => [
                self::GZ_REPORT_KEY,
                5,
                [
                    [
                        'name' => 'curl_multi_select',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 26213,
                        'exclusive' => 739015631,
                        'exclusiveRelative' => 0.674246022792371,
                        'inclusive' => 739015631,
                        'inclusiveRelative' => 0.674246022792371,
                    ],
                    [
                        'name' => 'curl_multi_exec',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 26213,
                        'exclusive' => 203550323,
                        'exclusiveRelative' => 0.1857105451682286,
                        'inclusive' => 203550323,
                        'inclusiveRelative' => 0.1857105451682286,
                    ],
                    [
                        'name' => '::zend_compile_file',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 323,
                        'exclusive' => 32820091,
                        'exclusiveRelative' => 0.029943637043901294,
                        'inclusive' => 32820091,
                        'inclusiveRelative' => 0.029943637043901294,
                    ],
                    [
                        'name' => 'Composer\Util\Http\CurlDownloader::tick',
                        'file' => 'phar:///usr/local/bin/composer/src/Composer/Util/Http/CurlDownloader.php',
                        'lineNumber' => 325,
                        'calls' => 26246,
                        'exclusive' => 27650662,
                        'exclusiveRelative' => 0.02522727273826248,
                        'inclusive' => 1014551168,
                        'inclusiveRelative' => 0.9256327758828616,
                    ],
                    [
                        'name' => 'curl_getinfo',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 27422,
                        'exclusive' => 25821324,
                        'exclusiveRelative' => 0.023558263560237463,
                        'inclusive' => 25821324,
                        'inclusiveRelative' => 0.023558263560237463,
                    ],
                ],
            ],
            'zst, wt, limit 5' => [
                self::ZST_REPORT_KEY,
                5,
                [
                    [
                        'name' => 'curl_multi_select',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 27754,
                        'exclusive' => 731223122,
                        'exclusiveRelative' => 0.6550305723791655,
                        'inclusive' => 731223122,
                        'inclusiveRelative' => 0.6550305723791655,
                    ],
                    [
                        'name' => 'curl_multi_exec',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 27754,
                        'exclusive' => 232391936,
                        'exclusiveRelative' => 0.20817698220213338,
                        'inclusive' => 232391936,
                        'inclusiveRelative' => 0.20817698220213338,
                    ],
                    [
                        'name' => 'Composer\Util\Http\CurlDownloader::tick',
                        'file' => 'phar:///usr/local/bin/composer/src/Composer/Util/Http/CurlDownloader.php',
                        'lineNumber' => 325,
                        'calls' => 27809,
                        'exclusive' => 30662697,
                        'exclusiveRelative' => 0.027467681699757465,
                        'inclusive' => 1039374443,
                        'inclusiveRelative' => 0.9310729048780905,
                    ],
                    [
                        'name' => '::zend_compile_file',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 323,
                        'exclusive' => 30169983,
                        'exclusiveRelative' => 0.027026307892325775,
                        'inclusive' => 30169983,
                        'inclusiveRelative' => 0.027026307892325775,
                    ],
                    [
                        'name' => 'curl_getinfo',
                        'file' => '',
                        'lineNumber' => 0,
                        'calls' => 28817,
                        'exclusive' => 28541023,
                        'exclusiveRelative' => 0.02556708352006534,
                        'inclusive' => 28541023,
                        'inclusiveRelative' => 0.02556708352006534,
                    ],
                ],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    /**
     * @param list<FlatProfileEntry> $expectedResult
     */
    #[DataProvider('getFlatProfileResultDataProvider')]
    public function testGetFlatProfileResult(
        string $reportKey,
        int $limit,
        array $expectedResult,
    ): void {
        self::assertSame(
            $expectedResult,
            self::makeProvider()->getFlatProfile($reportKey, 'wt', $limit),
        );
    }

    public function testGetFlatProfileSortsByExclusiveDesc(): void
    {
        $entries = self::makeProvider()->getFlatProfile(
            self::GZ_REPORT_KEY,
            'wt',
            200,
        );
        $exclusives = array_column($entries, 'exclusive');

        $sorted = $exclusives;
        rsort($sorted);

        self::assertNotEmpty($entries);
        self::assertSame($sorted, $exclusives);
    }

    public function testGetFlatProfileRespectsLimit(): void
    {
        self::assertCount(
            5,
            self::makeProvider()->getFlatProfile(self::GZ_REPORT_KEY, 'wt', 5),
        );
    }

    public function testGetFlatProfileEntriesAreWellFormed(): void
    {
        $entries = self::makeProvider()->getFlatProfile(
            self::GZ_REPORT_KEY,
            'wt',
            200,
        );

        foreach ($entries as $entry) {
            self::assertGreaterThanOrEqual(1, $entry['calls']);
            self::assertGreaterThanOrEqual(0, $entry['exclusive']);
            // Self metric can never exceed the function's inclusive metric.
            self::assertLessThanOrEqual($entry['inclusive'], $entry['exclusive']);
            // Relatives are shares of the whole-program total, so within [0, 1] and
            // ordered the same way as the absolute values they mirror.
            self::assertGreaterThanOrEqual(0.0, $entry['exclusiveRelative']);
            self::assertLessThanOrEqual(
                $entry['inclusiveRelative'],
                $entry['exclusiveRelative'],
            );
            self::assertLessThanOrEqual(1.0, $entry['inclusiveRelative']);

            if ($entry['inclusive'] > 0) {
                // exclusiveRelative and inclusiveRelative share the same denominator,
                // so their ratio reproduces the absolute exclusive/inclusive ratio.
                self::assertEqualsWithDelta(
                    $entry['exclusive'] / $entry['inclusive'],
                    $entry['exclusiveRelative'] / $entry['inclusiveRelative'],
                    1e-9,
                );
            }
        }
    }

    public function testGetFlatProfileReportsEntryPointInclusiveMetric(): void
    {
        $entries = self::makeProvider()->getFlatProfile(
            self::GZ_REPORT_KEY,
            'wt',
            500,
        );

        $findEntryByName = function (string $name) use ($entries): ?array {
            foreach ($entries as $entry) {
                if ($entry['name'] === $name) {
                    return $entry;
                }
            }

            return null;
        };

        $composer = $findEntryByName('/usr/local/bin/composer');

        if ($composer === null) {
            self::fail('flat profile should list the entry point');
        }

        // The entry point is invoked once and is its own outermost frame, so its
        // inclusive metric equals the wall time of that single top-level call.
        self::assertSame(1, $composer['calls']);
        self::assertSame(1095197049, $composer['inclusive']);
        self::assertSame('/usr/local/bin/composer', $composer['file']);
        self::assertSame(1, $composer['lineNumber']);
    }

    /**
     * @return array<string, array{string, string, class-string<\Throwable>, string}>
     */
    public static function getFlatProfileRejectsInvalidArgsDataProvider(): array
    {
        return [
            'path traversal in key' => [
                '../etc/passwd', 'wt',
                \InvalidArgumentException::class, '/Invalid report key/',
            ],
            'unknown report' => [
                'does-not-exist', 'wt',
                \RuntimeException::class, '/Cannot read metadata/',
            ],
            'metric not enabled in report' => [
                self::GZ_REPORT_KEY, 'ct',
                \InvalidArgumentException::class, "/Metric 'ct' not recorded/",
            ],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('getFlatProfileRejectsInvalidArgsDataProvider')]
    public function testGetFlatProfileRejectsInvalidArgs(
        string $reportKey,
        string $metric,
        string $exception,
        string $messagePattern,
    ): void {
        $this->expectException($exception);
        $this->expectExceptionMessageMatches($messagePattern);

        self::makeProvider()->getFlatProfile($reportKey, $metric, 50);
    }

    /**
     * @return array<string, array{
     *     string,
     *     string,
     *     float,
     *     array{metric: string, root: CallTreeNode},
     * }>
     */
    public static function getCallersResultDataProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength -- wide, deeply-nested call-graph fixture literals
        return [
            'gz, composer, thr = 0.01' => [
                self::GZ_REPORT_KEY,
                '/usr/local/bin/composer',
                0.01,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => '/usr/local/bin/composer',
                        'value' => 1095197049,
                        'calls' => 1,
                        'file' => '/usr/local/bin/composer',
                        'lineNumber' => 1,
                        'children' => [],
                    ],
                ],
            ],
            'gz, symfony console run, thr = 0.0' => [
                self::GZ_REPORT_KEY,
                "Symfony\\Component\\Console\\Application::run",
                0.0,
                [
                    'metric' => 'wt',
                    'root' => [
                        'name' => 'Symfony\Component\Console\Application::run',
                        'value' => 1082553317,
                        'calls' => 1,
                        'file' => 'phar:///usr/local/bin/composer/vendor/symfony/console/Application.php',
                        'lineNumber' => 137,
                        'children' =>  [
                            [
                                'name' => 'Composer\Console\Application::run',
                                'value' => 1082553317,
                                'calls' => 1,
                                'file' => 'phar:///usr/local/bin/composer/src/Composer/Console/Application.php',
                                'lineNumber' => 133,
                                'children' => [
                                    [
                                        'name' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'value' => 1082553317,
                                        'calls' => 1,
                                        'file' => 'phar:///usr/local/bin/composer/bin/composer',
                                        'lineNumber' => 1,
                                        'children' => [
                                            [
                                                'name' => '/usr/local/bin/composer',
                                                'value' => 1082553317,
                                                'calls' => 1,
                                                'file' => '/usr/local/bin/composer',
                                                'lineNumber' => 1,
                                                'children' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    /**
     * @param array{metric: string, root: CallTreeNode} $expectedResult
     */
    #[DataProvider('getCallersResultDataProvider')]
    public function testGetCallersResult(
        string $reportKey,
        string $function,
        float $pruningRelativeThreshold,
        array $expectedResult,
    ): void {
        $result = self::makeProvider()->getCallers(
            $reportKey,
            $function,
            'wt',
            $pruningRelativeThreshold,
        );
        $result['root'] = self::truncateToDepth($result['root'], 4);

        self::assertSame($expectedResult, $result);
    }

    public function testGetCallersOnEntryPointReturnsRootWithNoCallers(): void
    {
        $result = self::makeProvider()->getCallers(
            self::GZ_REPORT_KEY,
            '/usr/local/bin/composer',
            'wt',
            0.0,
        );

        self::assertSame(
            [
                'metric' => 'wt',
                'root'   => [
                    'name'       => '/usr/local/bin/composer',
                    'value'      => 1095197049,
                    'calls'      => 1,
                    'file'       => '/usr/local/bin/composer',
                    'lineNumber' => 1,
                    'children'   => [],
                ],
            ],
            $result,
        );
    }

    public function testGetCallersRootValueMatchesFlatProfileInclusive(): void
    {
        $toolProvider = self::makeProvider();
        $profile = $toolProvider->getFlatProfile(
            self::GZ_REPORT_KEY,
            'wt',
            1,
        );
        if ($profile === []) {
            self::fail('flat profile should not be empty');
        }

        $top = $profile[0];

        $callers = $toolProvider->getCallers(
            self::GZ_REPORT_KEY,
            $top['name'],
            'wt',
            0.0,
        );

        // Both views attribute a function's metric only at the outermost recursive
        // frame, so the inverted root carries exactly its flat-profile inclusive total.
        self::assertSame($top['name'], $callers['root']['name']);
        self::assertSame($top['inclusive'], $callers['root']['value']);
    }

    public function testGetCallersChainReachesEntryPointAndDecreasesUpward(): void
    {
        $result = self::makeProvider()->getCallers(
            self::GZ_REPORT_KEY,
            "Symfony\\Component\\Console\\Application::run",
            'wt',
            0.0,
        );

        self::assertGreaterThan(0, $result['root']['value']);
        self::assertNotEmpty($result['root']['children']);

        $collectNames = function (array $node) use (&$collectNames): array {
            $name = $node['name'] ?? '';
            $names = [is_string($name) ? $name : ''];

            $children = $node['children'] ?? [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $names = array_merge($names, $collectNames($child));
                    }
                }
            }

            return $names;
        };

        self::assertContains(
            '/usr/local/bin/composer',
            $collectNames($result['root']),
            'walking up the callers should reach the entry point',
        );
        // A caller can only account for a share of the value of the function it calls.
        $assertCallerValuesNonIncreasing = function (
            array $node,
        ) use (&$assertCallerValuesNonIncreasing): void {
            $parentValue = $node['value'] ?? 0;
            $children = $node['children'] ?? [];
            if (!is_array($children)) {
                return;
            }

            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }

                self::assertLessThanOrEqual($parentValue, $child['value'] ?? 0);
                $assertCallerValuesNonIncreasing($child);
            }
        };
        $assertCallerValuesNonIncreasing($result['root']);
    }

    /**
     * @return array<string, array{string, string, string, class-string<\Throwable>, string}>
     */
    public static function getCallersRejectsInvalidArgsDataProvider(): array
    {
        return [
            'path traversal in key' => [
                '../etc/passwd', 'main', 'wt',
                \InvalidArgumentException::class, '/Invalid report key/',
            ],
            'unknown report' => [
                'does-not-exist', 'main', 'wt',
                \RuntimeException::class, '/Cannot read metadata/',
            ],
            'metric not enabled in report' => [
                self::GZ_REPORT_KEY, 'main', 'ct',
                \InvalidArgumentException::class, "/Metric 'ct' not recorded/",
            ],
            'unknown function' => [
                self::GZ_REPORT_KEY, 'No\\Such\\Function::nope', 'wt',
                \InvalidArgumentException::class, '/not found/',
            ],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('getCallersRejectsInvalidArgsDataProvider')]
    public function testGetCallersRejectsInvalidArgs(
        string $reportKey,
        string $function,
        string $metric,
        string $exception,
        string $messagePattern,
    ): void {
        $this->expectException($exception);
        $this->expectExceptionMessageMatches($messagePattern);

        self::makeProvider()->getCallers($reportKey, $function, $metric, 0.0);
    }

    private static function makeProvider(
        ?string $dataDir = null,
        ?ClockInterface $clock = null,
    ): SpxToolProvider {
        $reportStore = new SpxReportStore(
            $dataDir ?? self::$fixturesDir,
            new NullLogger(),
        );

        return new SpxToolProvider(
            $reportStore,
            new CallGraphAggregator($reportStore),
            $clock ?? new SystemClock(),
        );
    }

    /**
     * @param array<string, string> $files relative path => content, written before $body runs
     */
    private static function withTempDir(array $files, callable $body): void
    {
        $dir = sys_get_temp_dir() . '/spx-mcp-tests-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            foreach ($files as $name => $content) {
                file_put_contents($dir . '/' . $name, $content);
            }

            $body($dir);
        } finally {
            foreach (array_keys($files) as $name) {
                @unlink($dir . '/' . $name);
            }

            rmdir($dir);
        }
    }

    /**
     * Return a copy of a call-graph node with descendants deeper than $maxDepth
     * levels below it removed. Depth 0 keeps the node itself but drops all of its
     * children.
     *
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function truncateToDepth(array $node, int $maxDepth): array
    {
        $children = $node['children'] ?? [];
        if ($maxDepth <= 0 || !is_array($children)) {
            $node['children'] = [];

            return $node;
        }

        $truncated = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $truncated[] = self::truncateToDepth($child, $maxDepth - 1);
            }
        }

        $node['children'] = $truncated;

        return $node;
    }

}
