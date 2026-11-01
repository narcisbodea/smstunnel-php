<?php

declare(strict_types=1);

namespace SmsTunnel\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SmsTunnel\Exception\TransportException;

/**
 * Sends through any PSR-18 HTTP client (Guzzle, Symfony HttpClient, ...), so the client shares your app's
 * proxy, retry and logging setup.
 *
 * Needs `psr/http-client` and `psr/http-factory` (your HTTP client brings them).
 *
 * ```php
 * $factory = new \GuzzleHttp\Psr7\HttpFactory();
 * $transport = new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 30]), $factory, $factory);
 * ```
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException('Could not reach SMSTunnel: ' . $exception->getMessage(), 0, $exception);
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[(string) $name] = implode(', ', $values);
        }

        return new HttpResponse($response->getStatusCode(), (string) $response->getBody(), $responseHeaders);
    }
}
