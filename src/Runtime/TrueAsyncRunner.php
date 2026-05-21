<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Runtime;

use Psr\Container\ContainerInterface;
use TrueAsync\Yii3\Server\TrueAsyncServer;
use Yiisoft\Yii\Runner\ApplicationRunner;

/**
 * Runs a Yii3 application on the TrueAsync HTTP server.
 *
 * Reuses the standard Yii3 container-building pipeline from
 * {@see ApplicationRunner} (the `*-web` config groups), then hands a lazy
 * container factory to {@see TrueAsyncServer}. The container is built per
 * worker, inside the worker thread — so `getContainer()` is never called
 * before the server starts.
 *
 * Entry point usage (`public/index.php`):
 *
 * ```php
 * (new TrueAsyncRunner(rootPath: dirname(__DIR__)))->run();
 * ```
 */
final class TrueAsyncRunner extends ApplicationRunner
{
    /**
     * @param array<string, mixed> $serverOptions Server options: host, port, workers.
     */
    public function __construct(
        string $rootPath,
        bool $debug = false,
        bool $checkEvents = false,
        ?string $environment = null,
        private readonly array $serverOptions = [],
    ) {
        parent::__construct(
            $rootPath,
            $debug,
            $checkEvents,
            $environment,
            'bootstrap-web',
            'events-web',
            'di-web',
            'di-providers-web',
            'di-delegates-web',
            'di-tags-web',
            'params-web',
            ['params'],
            ['events'],
            [],
            'config',
            'vendor',
            '.merge-plan.php',
        );
    }

    public function run(): void
    {
        $options = $this->serverOptions;
        $options['debug'] ??= $this->debug;
        $options['autoload'] ??= $this->rootPath . '/vendor/autoload.php';

        $server = new TrueAsyncServer(
            $options,
            fn(): ContainerInterface => $this->getContainer(),
        );

        $server->start();
    }
}
