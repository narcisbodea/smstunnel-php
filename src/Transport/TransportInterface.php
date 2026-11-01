<?php

declare(strict_types=1);

namespace SmsTunnel\Transport;

use SmsTunnel\Exception\TransportException;

/**
 * Sends one HTTP request to SMSTunnel and hands back the answer, whatever its status.
 *
 * The client ships with {@see CurlTransport} (the default) and {@see Psr18Transport} (any PSR-18 client).
 * Implement this to plug in something else, or to fake SMSTunnel in your own tests.
 */
interface TransportInterface
{
    /**
     * @param string                $method  `GET` or `POST`
     * @param array<string, string> $headers header name => value
     * @param string|null           $body    the JSON body, or null for none
     *
     * @throws TransportException when SMSTunnel could not be reached (DNS, TLS, timeout, ...)
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
