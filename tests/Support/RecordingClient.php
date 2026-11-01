<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that remembers the request and answers with a fixed response or exception.
 */
final class RecordingClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(private readonly ResponseInterface|ClientExceptionInterface $answer)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        if ($this->answer instanceof ClientExceptionInterface) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
