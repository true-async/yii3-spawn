<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrueAsync\Yii3\Runtime\TrueAsyncRunner;
use Yiisoft\Yii\Runner\RunnerInterface;

/**
 * Smoke test: the runner constructs and exposes the Yii3 runner contract
 * without touching the TrueAsync extension or the container.
 */
final class TrueAsyncRunnerTest extends TestCase
{
    public function testRunnerImplementsRunnerInterface(): void
    {
        $runner = new TrueAsyncRunner(
            rootPath: '/tmp',
            serverOptions: ['host' => '127.0.0.1', 'port' => 8080],
        );

        self::assertInstanceOf(RunnerInterface::class, $runner);
    }
}
