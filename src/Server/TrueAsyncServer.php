<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Server;

use Closure;
use Psr\Container\ContainerInterface;
use Throwable;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use Yiisoft\Yii\Http\Application;

use function is_file;
use function microtime;
use function sprintf;

/**
 * TrueAsync HTTP server adapter for Yii3.
 *
 * Owns the {@see HttpServer}, wires the request handler, and bridges the
 * TrueAsync request/response to the Yii3 PSR-7 middleware stack.
 *
 * Concurrency model: with more than one worker the server's built-in pool
 * deep-copies this object into each worker thread (no manual `spawn_thread`).
 * The container is therefore built lazily, per worker, on the first request —
 * `$containerFactory` must not be invoked before {@see start()}.
 */
final class TrueAsyncServer
{
    private readonly PsrRequestFactory $requestFactory;
    private readonly PsrResponseEmitter $responseEmitter;

    private ?ContainerInterface $container = null;
    private ?Application $application = null;

    /**
     * @param array<string, mixed> $options Server options (host, port, workers, debug, autoload).
     * @param Closure(): ContainerInterface $containerFactory Builds the Yii3 DI container; called once per worker.
     */
    public function __construct(
        private readonly array $options,
        private readonly Closure $containerFactory,
    ) {
        $this->requestFactory = new PsrRequestFactory();
        $this->responseEmitter = new PsrResponseEmitter();
    }

    public function start(): void
    {
        $config = new HttpServerConfig();
        $config->addListener(
            (string) ($this->options['host'] ?? '0.0.0.0'),
            (int) ($this->options['port'] ?? 8080),
        );

        $workers = (int) ($this->options['workers'] ?? 1);
        if ($workers > 1) {
            $config->setWorkers($workers);
            $config->setBootloader($this->bootloader());
        }

        $server = new HttpServer($config);
        $server->addHttpHandler($this->handle(...));
        $server->start();
    }

    private function handle(HttpRequest $request, HttpResponse $response): void
    {
        try {
            $request->awaitBody();

            $application = $this->application();
            $psrRequest = $this->requestFactory->create($request)
                ->withAttribute('applicationStartTime', microtime(true));

            $psrResponse = $application->handle($psrRequest);
            $this->responseEmitter->emit($psrResponse, $response);
            $application->afterEmit($psrResponse);
        } catch (Throwable $e) {
            $this->emitError($e, $response);
        }
    }

    /**
     * Builds the container and Yii3 application once per worker, then caches them.
     */
    private function application(): Application
    {
        if ($this->application === null) {
            $this->container = ($this->containerFactory)();

            /** @var Application $application */
            $application = $this->container->get(Application::class);
            $application->start();

            $this->application = $application;
        }

        return $this->application;
    }

    /**
     * Per-worker bootloader: restores the Composer autoloader inside the
     * freshly spawned worker thread before its task loop starts.
     */
    private function bootloader(): Closure
    {
        $autoload = (string) ($this->options['autoload'] ?? '');

        return static function () use ($autoload): void {
            if ($autoload !== '' && is_file($autoload)) {
                require_once $autoload;
            }
        };
    }

    private function emitError(Throwable $e, HttpResponse $response): void
    {
        if ($response->isClosed() || $response->isHeadersSent()) {
            return;
        }

        $body = ($this->options['debug'] ?? false)
            ? sprintf(
                "%s: %s\n%s:%d\n%s",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            )
            : 'Internal Server Error';

        $response
            ->setStatusCode(500)
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->end($body);
    }
}
