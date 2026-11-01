<?php

declare(strict_types=1);

namespace SmsTunnel\Transport;

use SmsTunnel\Exception\TransportException;

/**
 * The default transport: PHP's curl extension, nothing else.
 *
 * Redirects are not followed, so the API key is never sent to another address.
 */
final class CurlTransport implements TransportInterface
{
    /**
     * @param float              $timeout        seconds for the whole request
     * @param float              $connectTimeout seconds to open the connection
     * @param array<int, mixed>  $curlOptions    extra `CURLOPT_*` options (a proxy, for example); they win over the defaults
     */
    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly array $curlOptions = [],
    ) {
        if (!\extension_loaded('curl')) {
            throw new \LogicException(
                'The curl extension is not installed. Install ext-curl, or give the Client a Psr18Transport.',
            );
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Could not start a curl request.');
        }

        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            \CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new status line (after `100 Continue`, for instance): start over.
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return \strlen($line);
            },
        ];
        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, array_replace($options, $this->curlOptions));

        $result = curl_exec($handle);
        if (!\is_string($result)) {
            throw new TransportException(
                \sprintf('Could not reach SMSTunnel at %s: %s', self::origin($url), curl_error($handle)),
                curl_errno($handle),
            );
        }

        return new HttpResponse(curl_getinfo($handle, \CURLINFO_RESPONSE_CODE), $result, $responseHeaders);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
