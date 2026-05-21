<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Server;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UriFactory;
use Psr\Http\Message\ServerRequestInterface;
use TrueAsync\HttpRequest;

use function explode;
use function str_contains;
use function strpos;
use function substr;
use function trim;

/**
 * Converts a TrueAsync {@see HttpRequest} into a PSR-7 server request that the
 * Yii3 middleware stack can handle.
 */
final class PsrRequestFactory
{
    private readonly ServerRequestFactory $serverRequestFactory;
    private readonly UriFactory $uriFactory;
    private readonly StreamFactory $streamFactory;

    public function __construct()
    {
        $this->serverRequestFactory = new ServerRequestFactory();
        $this->uriFactory = new UriFactory();
        $this->streamFactory = new StreamFactory();
    }

    public function create(HttpRequest $request): ServerRequestInterface
    {
        $method = $request->getMethod();
        $headers = $request->getHeaders();
        $version = $request->getHttpVersion();
        $queryString = $this->queryString($request);

        $uri = $this->uriFactory->createUri()
            ->withScheme('http')
            ->withPath($request->getPath());

        $host = $headers['host'] ?? 'localhost';
        if (str_contains($host, ':')) {
            [$hostName, $port] = explode(':', $host, 2);
            $uri = $uri->withHost($hostName)->withPort((int) $port);
        } else {
            $uri = $uri->withHost($host);
        }

        if ($queryString !== '') {
            $uri = $uri->withQuery($queryString);
        }

        $serverParams = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $request->getUri(),
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/' . $version,
        ];

        $psrRequest = $this->serverRequestFactory
            ->createServerRequest($method, $uri, $serverParams)
            ->withProtocolVersion($version)
            ->withBody($this->streamFactory->createStream($request->getBody()))
            ->withQueryParams($request->getQuery())
            ->withCookieParams($this->cookies($request));

        foreach ($headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        // Only form bodies are pre-parsed by the server; JSON and other
        // payloads are left on the stream for Yii3's body-parser middleware.
        $post = $request->getPost();
        if ($post !== []) {
            $psrRequest = $psrRequest->withParsedBody($post);
        }

        return $psrRequest;
    }

    private function queryString(HttpRequest $request): string
    {
        $uri = $request->getUri();
        $pos = strpos($uri, '?');

        return $pos === false ? '' : substr($uri, $pos + 1);
    }

    /**
     * @return array<string, string>
     */
    private function cookies(HttpRequest $request): array
    {
        $header = $request->getHeader('cookie');
        if ($header === null || $header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (isset($parts[1])) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }
}
