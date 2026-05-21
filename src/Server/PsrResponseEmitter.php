<?php

declare(strict_types=1);

namespace TrueAsync\Yii3\Server;

use Psr\Http\Message\ResponseInterface;
use TrueAsync\HttpResponse;

/**
 * Writes a PSR-7 response produced by Yii3 into a TrueAsync {@see HttpResponse}.
 */
final class PsrResponseEmitter
{
    public function emit(ResponseInterface $psrResponse, HttpResponse $response): void
    {
        $response->setStatusCode($psrResponse->getStatusCode());

        $reasonPhrase = $psrResponse->getReasonPhrase();
        if ($reasonPhrase !== '') {
            $response->setReasonPhrase($reasonPhrase);
        }

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->setHeader($name, $values);
        }

        $response->end((string) $psrResponse->getBody());
    }
}
