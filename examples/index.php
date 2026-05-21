<?php

declare(strict_types=1);

/**
 * Example entry point for a Yii3 application running on the TrueAsync server.
 *
 * Copy this into your Yii3 project's `public/index.php`. It requires PHP 8.6+
 * with the TrueAsync extension and the TrueAsync server extension.
 */

use TrueAsync\Yii3\Runtime\TrueAsyncRunner;

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new TrueAsyncRunner(
    rootPath: dirname(__DIR__),
    debug: (bool) ($_ENV['YII_DEBUG'] ?? false),
    environment: $_ENV['YII_ENV'] ?? null,
    serverOptions: [
        'host' => '0.0.0.0',
        'port' => 8080,
        'workers' => 1, // > 1 enables the server's built-in worker pool
    ],
))->run();
