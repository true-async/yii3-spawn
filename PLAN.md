# yii3-spawn — Yii3 + TrueAsync server integration plan

This document describes how to adapt the **Yii3** framework to run on the
[TrueAsync server](https://github.com/true-async/server). It builds on the
existing `symfony-spawn` and `laravel-spawn` adapters — they share one
architectural skeleton, which is carried over to Yii3.

---

## 1. Goal

Run a standard Yii3 application on the TrueAsync server so that:

- the application is built **once per worker** (DI container, routes, config);
- requests inside a worker are handled **concurrently**, each in its own coroutine;
- **application code is unchanged** — controllers, middleware, ActiveRecord, and
  templates work as usual;
- request-scoped state is isolated between coroutines.

---

## 2. How the TrueAsync server works and what the existing adapters show

The TrueAsync server (`TrueAsync\HttpServer`) is a multi-threaded coroutine HTTP server:

- `spawn_thread()` starts N workers (usually one per CPU core);
- inside each worker `HttpServer` accepts connections and spawns a coroutine per
  request;
- coroutines within one worker share the process memory — so **all singletons and
  statics are shared**;
- `Async\request_context()` provides storage isolated per coroutine/scope — this is
  the key isolation primitive.

`symfony-spawn` and `laravel-spawn` solve the same problem the same way:

| Layer | Purpose |
|---|---|
| **Runtime / Runner** | entry point; configures `HttpServer`, builds the framework container per worker, launches the server's built-in worker pool |
| **Server adapter** | converts `TrueAsync\HttpRequest` ↔ the framework request object and back for the response |
| **Per-coroutine adapters** | wrap stateful singletons, moving their mutable state into `request_context()` |
| **DB pool + transactions** | enable the TrueAsync C-level PDO pool and isolate transaction nesting per coroutine |
| **Memory hygiene** | reset state accumulated during a request (identity map, etc.) |

Core idea: **shared read-only state stays shared** (config, route definitions,
translation caches), while **mutable per-request state is isolated**.

---

## 3. Yii3 architecture and where it breaks under concurrency

Yii3 is noticeably friendlier to async than Yii2 and Symfony:

- **no global `Yii::$app`** — no God singleton;
- the request (`Psr\Http\Message\ServerRequestInterface`) is a **local variable**
  flowing through the PSR-15 middleware stack, not a singleton. This removes the
  worst Yii2/Symfony problem;
- the DI container `Yiisoft\Di\Container` is built from config groups
  (`di-web`, `di-providers-web`, ...);
- the HTTP application is `Yiisoft\Yii\Http\Application`: `start()`, then
  `handle(ServerRequestInterface): ResponseInterface` through `MiddlewareDispatcher`,
  then `afterEmit()` / `shutdown()`;
- runners (`Yiisoft\Yii\Runner\ApplicationRunner`, `RunnerInterface` implementations)
  already abstract the entry point — there is an HTTP runner (`HttpApplicationRunner`)
  and a RoadRunner runner;
- **`Yiisoft\Di\StateResetter` already exists** — between requests the RoadRunner
  runner calls `StateResetter::reset()` to clear stateful services.

### The problem

`StateResetter` is designed for **sequential** worker reuse (RoadRunner: one request
at a time). Under TrueAsync, N coroutines run **concurrently** in one worker — a
"reset between requests" is incorrect here, because requests overlap in time. So the
stateful singletons that the RoadRunner mode simply resets must instead be
**isolated per coroutine** under async.

### Yii3 stateful singletons that need isolation

| Singleton | What it holds | When it mutates |
|---|---|---|
| `Yiisoft\Router\CurrentRoute` | matched route, arguments, URI | router middleware |
| `Yiisoft\User\CurrentUser` | authenticated identity, permission-check cache | auth middleware, app code |
| `Yiisoft\Session\SessionInterface` | session data, flash messages | whole request lifecycle |
| `Yiisoft\RequestProvider\RequestProvider` | current `ServerRequest` for non-middleware code | `RequestCatcherMiddleware` |
| View state (`Yiisoft\View\View` / `ViewRenderer`) | shared template parameters, blocks, title | controllers, layout |
| Error handler | the stored current request for error rendering | on exception |
| `Yiisoft\Db\Connection\ConnectionInterface` | the active transaction object, savepoint level | `transaction()` / `beginTransaction()` |

Everything else (container definitions, routes, config, translation loader, DB
schema) is read-only after build and safe to share.

---

## 4. Adaptation strategy

**Chosen: shared container + state isolation via `request_context()`**
(the same approach as `symfony-spawn` / `laravel-spawn`).

- the container is built once per worker;
- each request coroutine runs in its own child `Scope`, so `request_context()` is
  unique per request;
- the problematic singletons are replaced with async variants via **DI
  delegates/decorators** in a separate config group (`di-web` override) wired in by
  our runner;
- the replacement is transparent: the classes implement the same Yii3 interfaces as
  the originals.

The alternative — a separate child container per coroutine — is rejected:
`Yiisoft\Di\Container` is not meant for cheap child-container creation, and
rebuilding request-scoped services per request is more expensive than targeted
isolation.

---

## 5. Adapter components

| Component | Adapter (`TrueAsync\Yii3\…`) | What is isolated |
|---|---|---|
| Runner | `Runtime\TrueAsyncRunner` | entry point: workers + `HttpServer` |
| Worker runtime | `Runtime\WorkerRuntime` | per-worker container/`Application`, built once in the bootloader |
| Server | `Server\TrueAsyncServer` | `HttpRequest` ↔ PSR-7 ↔ `HttpResponse` |
| PSR-7 bridge | `Server\PsrRequestFactory` / `PsrResponseEmitter` | request/response conversion |
| Current route | `Router\AsyncCurrentRoute` | matched route per coroutine |
| Authentication | `User\AsyncCurrentUser` | identity and permission cache per coroutine |
| Session | `Session\AsyncSession` | session data and flash per coroutine |
| Request provider | `Http\AsyncRequestProvider` | current `ServerRequest` per coroutine |
| View | `View\AsyncViewState` | shared template params/blocks per coroutine |
| DB: driver | `Db\TrueAsyncPgsqlDriver` | a single overridden `createConnection()` (see below) |
| DB: transactions | `Db\AsyncTransactionState` | nesting/savepoint level per coroutine |
| DI | `Di\AsyncConfigGroup` (config set) | swap singletons for async versions |
| Memory hygiene | `Runtime\RequestCleanup` | reset identity map / state after a request |

### Safe with no adaptation

Controllers, middleware, hydrator/`request-model`, validation, cache backends,
HTTP client, logger, mailer, translation catalogs — either created per request or
stateless.

### DB driver — only PDO creation differs

In Yii3's `yiisoft/db`, DSN assembly is separated from connection creation:
`new PDO(...)` is called in exactly one place — `AbstractPdoDriver::createConnection()`
(public, not `final`). So the driver adapter is trivial — extend
`Yiisoft\Db\Pgsql\Driver` and override **only** `createConnection()`:

```php
final class TrueAsyncPgsqlDriver extends \Yiisoft\Db\Pgsql\Driver
{
    public function createConnection(): \PDO
    {
        // TrueAsync changes the PDO constructor signature:
        // the password goes into the DSN, arg3 is errmode (int), arg4 is attributes
        // (including pool options: ATTR_POOL_ENABLED / MIN / MAX / ...).
        return new \PDO(
            $this->dsn . ';password=' . $this->password,
            $this->username,
            \PDO::ERRMODE_EXCEPTION,
            $this->attributes,
        );
    }
}
```

There is no need to wrap `connect()`, `Connection`, or `Schema` — unlike Doctrine
DBAL in `symfony-spawn`, where the DSN was built inside `connect()` and the whole
method had to be overridden. Pool attributes reach `$this->attributes` through the
normal `yiisoft/db` config (PDO constructor arg4).

---

## 6. Implementation stages

Each stage is self-contained, with an explicit done criterion.

### Stage 0 — repository scaffold ✅
`composer.json`, `README.md`, `.gitignore`, `PLAN.md`, `src/` layout.
**Done:** the repository clones and `composer install` passes.

### Stage 1 — Runner + Server, single worker ✅ (code complete)
`Runtime\TrueAsyncRunner` (extends `ApplicationRunner`, implements
`Yiisoft\Yii\Runner\RunnerInterface`), `Server\TrueAsyncServer` on top of
`TrueAsync\HttpServer`, the PSR-7 bridge (`PsrRequestFactory` / `PsrResponseEmitter`).
The container is built via the base `ApplicationRunner` (the `*-web` config groups)
and held per worker by `Runtime\WorkerRuntime`; the request runs through
`Yiisoft\Yii\Http\Application::handle()`. `TrueAsync\*` IDE stubs vendored under
`stubs/`.
**Done:** code complete, lint + PHPStan (level 6) clean. The live "returns 200"
smoke test is pending a demo Yii3 app + the running server extension.

### Stage 2 — multi-worker mode
Use the server's built-in worker pool — `HttpServerConfig::setWorkers(N)` +
`setBootloader()`. No manual `spawn_thread`: the server replicates the config +
handler to each worker. The container is built **eagerly in the per-worker
bootloader** (Composer autoload + `WorkerRuntime::boot()`), before that worker
accepts any request — this avoids both a first-request latency spike and the race
that lazy "build on first request" would create under coroutine-per-request
concurrency. The wiring already exists in `TrueAsyncServer`; this stage validates
and hardens it.
**Done:** N workers serve requests; the default route is stable under `h2load`.

### Stage 3 — per-coroutine state isolation
`AsyncCurrentRoute`, `AsyncRequestProvider`, `AsyncViewState`, `AsyncSession`.
Swapped in via the `di-web` config group wired in by the runner.
**Done:** a concurrent test (two coroutines with different routes/sessions) sees no
foreign state.

### Stage 4 — authentication
`AsyncCurrentUser` — identity and permission-check cache in `request_context()`.
**Done:** concurrent requests with different identities are isolated; flash messages
do not leak between coroutines.

### Stage 5 — database
`TrueAsyncPgsqlDriver` — only `createConnection()` overridden (TrueAsync PDO pool;
password in DSN, errmode as arg3), `AsyncTransactionState` for per-coroutine
transaction nesting, pool warm-up in the first coroutine.
**Done:** ActiveRecord/`yiisoft/db` works; concurrent transactions do not interfere;
an RPS gain from the pool is visible (see `feedback_pdo_pool_stmt_cache`).

### Stage 6 — memory hygiene and resilience
Reset per-request state and the identity map after a request; roll back any
transaction left open before returning the connection to the pool; an OOM/exception
firewall so the worker does not crash with SEGV.
**Done:** sustained load without RSS growth and without worker crashes.

### Stage 7 — static files, TLS, HTTP/2
Wire `StaticHandler`, TLS listeners, and protocols through config (modeled on
`TrueAsyncServer::buildConfig()` from `symfony-spawn`).
**Done:** static files, TLS, and h2 work; config covers the settings.

### Stage 8 — tests, benchmark, documentation
PHPUnit for the adapters, concurrent integration tests, a HttpArena run,
`ADAPTATION.md` (what is adapted / what is unsafe / how to write async-safe code —
modeled on `laravel-spawn`).
**Done:** CI is green, RPS benchmark numbers are recorded.

---

## 7. Repository layout

```
yii3-spawn/
├── composer.json
├── README.md
├── PLAN.md                  # this document
├── ADAPTATION.md            # added in stage 8
├── config/                  # Yii3 config groups with singleton overrides
│   └── di-web.php
├── src/
│   ├── Runtime/
│   │   ├── TrueAsyncRunner.php
│   │   └── RequestCleanup.php
│   ├── Server/
│   │   ├── TrueAsyncServer.php
│   │   ├── PsrRequestFactory.php
│   │   └── PsrResponseEmitter.php
│   ├── Router/AsyncCurrentRoute.php
│   ├── User/AsyncCurrentUser.php
│   ├── Session/AsyncSession.php
│   ├── Http/AsyncRequestProvider.php
│   ├── View/AsyncViewState.php
│   ├── Db/
│   │   ├── TrueAsyncPgsqlDriver.php
│   │   └── AsyncTransactionState.php
│   └── ScopedKey.php         # enum keys for request_context()
└── tests/
```

---

## 8. Open questions and risks

1. **Exact Yii3 class signatures.** `yiisoft/*` versions (especially `router`,
   `user`, `db`) differ across majors — at stage 1 the versions must be pinned in
   `composer.json` against a real `yiisoft/app` and the interfaces verified.
2. **`final` classes.** If `CurrentRoute` / `Session` are declared `final`,
   replacement via inheritance is impossible — then use a decorator + DI delegate
   (like `AsyncTranslator` in `symfony-spawn`).
3. **`StateResetter`.** Make sure its reset is not invoked on shared singletons in
   async mode — otherwise it would clobber another coroutine's state. Most likely
   `StateResetter::reset()` should not be called at all in the async runner.
4. **Internal statics in Yii3 packages.** Spot-check static properties across
   `yiisoft/*` (logging, profiling, error handler) — modeled on the
   `MutableStaticPropertyRule` PHPStan rule from `laravel-spawn`.
5. **PDO driver.** TrueAsync changes the `PDO` constructor signature (password in
   DSN, errmode as arg3). In Yii3 this is contained to a single method —
   `AbstractPdoDriver::createConnection()` — so only that one method is overridden
   (see §5), no wider wrapping needed.
6. **Pool warm-up.** The PDO pool is created only while the coroutine scheduler is
   running — warm it up in the first coroutine, not at boot time (see the
   `symfony-spawn` `DevServer`).

---

## 9. References

- TrueAsync server — https://github.com/true-async/server
- PHP TrueAsync — https://github.com/true-async/php-async
- Adapter examples — `~/symfony-spawn`, `~/laravel-spawn`
- Yii3 HTTP runner — `yiisoft/yii-runner-http`, `yiisoft/yii-runner-roadrunner`
