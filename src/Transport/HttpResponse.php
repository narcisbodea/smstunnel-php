<?php

declare(strict_types=1);

namespace SmsTunnel\Transport;

/**
 * The raw answer from SMSTunnel, as a transport hands it to the client.
 */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers header name => value (names are matched without regard to case)
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
