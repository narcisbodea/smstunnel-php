<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Support;

use SmsTunnel\Transport\HttpResponse;
use SmsTunnel\Transport\TransportInterface;

/**
 * Answers with queued responses and remembers every request.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    /**
     * @param array<string, mixed>|string|null $body an array is sent as JSON
     * @param array<string, string>            $headers
     */
    public function respond(int $status, array|string|null $body, array $headers = []): self
    {
        $text = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : (string) $body;
        $this->queue[] = new HttpResponse($status, $text, $headers);

        return $this;
    }

    public function fail(\Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(\sprintf('No response queued for %s %s', $method, $url));
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
     */
    public function lastRequest(): array
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $body = $this->lastRequest()['body'];
        $decoded = json_decode((string) $body, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \LogicException('The last request had no JSON object body.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
