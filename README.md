# yii3-spawn

Yii3 adapter for [PHP TrueAsync](https://github.com/true-async/php-async) — runs a Yii3
application on the [TrueAsync HTTP server](https://github.com/true-async/server) with
**coroutine-per-request isolation**.

> Status: **planning / WIP**. See [PLAN.md](PLAN.md) for the full design and roadmap.

## What it does

A standard Yii3 application is built once per worker. The TrueAsync server then handles
many HTTP requests **concurrently** inside that single worker — each request runs in its
own coroutine. `yii3-spawn` makes the stateful Yii3 singletons safe under that concurrency
by backing their per-request state with `Async\request_context()`, so application code
keeps working unchanged.

## Requirements

- PHP 8.6+ with the **TrueAsync** extension
- TrueAsync server extension (`TrueAsync\HttpServer`)
- Yii3 (`yiisoft/yii-http`, `yiisoft/di`, `yiisoft/yii-runner`)

## Installation

```bash
composer require true-async/yii3-spawn
```

## Running

Wire the runner into your Yii3 project's `public/index.php` (see
[`examples/index.php`](examples/index.php)):

```php
use TrueAsync\Yii3\Runtime\TrueAsyncRunner;

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new TrueAsyncRunner(
    rootPath: dirname(__DIR__),
    serverOptions: ['host' => '0.0.0.0', 'port' => 8080, 'workers' => 1],
))->run();
```

`workers > 1` enables the TrueAsync server's built-in worker pool — no manual
thread spawning.

See [PLAN.md](PLAN.md) for architecture, the list of adapted components, and the
implementation roadmap.

## License

MIT
