<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Runtime;

use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Yiisoft\Yii\Http\Application;

/**
 * Worker-local Yii3 runtime holder.
 *
 * Holds the DI container and {@see Application} for one worker. In ZTS PHP each
 * worker thread has its own copy of these static properties, so the runtime is
 * naturally isolated per worker.
 *
 * The container is built once, eagerly, in {@see boot()} — called from the
 * server's per-worker bootloader before that worker starts accepting requests.
 * This avoids both a first-request latency spike and the race that lazy
 * "build on first request" creates under coroutine-per-request concurrency.
 */
final class WorkerRuntime
{
    private static ?Application $application = null;

    /**
     * Builds the container and Yii3 application for the current worker.
     * Idempotent: a second call is a no-op.
     *
     * @param Closure(): ContainerInterface $containerFactory
     */
    public static function boot(Closure $containerFactory): void
    {
        if (self::$application !== null) {
            return;
        }

        $container = $containerFactory();

        /** @var Application $application */
        $application = $container->get(Application::class);
        $application->start();

        self::$application = $application;
    }

    /**
     * Returns the booted application for the current worker.
     */
    public static function application(): Application
    {
        return self::$application
            ?? throw new RuntimeException('Worker runtime is not booted. Call WorkerRuntime::boot() first.');
    }
}
