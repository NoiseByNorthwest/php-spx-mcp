#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use NoiseByNorthwest\SpxMcp\SpxReportStore;
use NoiseByNorthwest\SpxMcp\StderrLogger;
use NoiseByNorthwest\SpxMcp\SystemClock;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

$logger = new StderrLogger();

$dataDir = getenv('SPX_DATA_DIR');

$container = new BasicContainer();
$container->set(LoggerInterface::class, $logger);
$container->set(ClockInterface::class, new SystemClock());
$container->set(
    SpxReportStore::class,
    new SpxReportStore($dataDir !== false && $dataDir !== '' ? $dataDir : '/tmp/spx', $logger),
);

$server = Server::make()
    ->withServerInfo('php-spx-mcp', '0.1.0')
    ->withLogger($logger)
    ->withContainer($container)
    ->build();

$server->discover(dirname(__DIR__), ['src']);

$server->listen(new StdioServerTransport());
