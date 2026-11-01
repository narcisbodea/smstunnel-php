<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * SMSTunnel answered, but with an error.
 *
 * - {@see getStatusCode()}: the HTTP status (also the exception's `getCode()`).
 * - {@see getErrorCode()}: SMSTunnel's own code when it sends one, such as `PERMISSION_REQUIRED`.
 * - `getMessage()`: what went wrong, in SMSTunnel's words.
 *
 * Statuses worth handling, each with its own subclass:
 * - 401 {@see AuthenticationException}: the API key is wrong, expired, or its account is suspended.
 * - 403 {@see PermissionException}: the key lacks a permission, or the plan does not include the feature.
 * - 429 {@see RateLimitException}: too many requests; wait and try again.
 *
 * Others: 400 (the server refused a field, for example a number it cannot read, or a verification text
 * without a code), 404 (no such message or phone for this key), 5xx (a problem on SMSTunnel's side).
 */
class ApiException extends SmsTunnelException
{
    /**
     * @param array<string, string> $headers the response headers, names in lower case
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $errorCode = null,
        private readonly mixed $body = null,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Builds the exception that matches the status: one of the subclasses for 401, 403 and 429.
     *
     * @param mixed                 $body    the decoded JSON body, or the raw text when it was not JSON
     * @param array<string, string> $headers the response headers, names in lower case
     */
    public static function fromResponse(int $statusCode, mixed $body, array $headers = []): self
    {
        $message = self::messageOf($body, $statusCode);
        $errorCode = \is_array($body) && \is_string($body['code'] ?? null) ? $body['code'] : null;

        return match ($statusCode) {
            401 => new AuthenticationException($message, $statusCode, $errorCode, $body, $headers),
            403 => new PermissionException($message, $statusCode, $errorCode, $body, $headers),
            429 => new RateLimitException($message, $statusCode, $errorCode, $body, $headers),
            default => new self($message, $statusCode, $errorCode, $body, $headers),
        };
    }

    /** The HTTP status SMSTunnel answered with. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * SMSTunnel's code for errors your software can act on, for example:
     * - `PERMISSION_REQUIRED`: the key lacks a permission (see {@see PermissionException::getPermission()}).
     * - `DISABLED_BY_SUPPORT`: SMSTunnel support turned the key or the phone off.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** The body SMSTunnel sent: the decoded JSON (an array), the raw text, or null when it was empty. */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /** @return array<string, string> the response headers, names in lower case */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    private static function messageOf(mixed $body, int $statusCode): string
    {
        if (\is_string($body) && trim($body) !== '') {
            $text = trim(strip_tags($body));
            $text = (string) preg_replace('/\s+/', ' ', $text);

            if ($text === '') {
                return self::fallbackMessage($statusCode);
            }

            // A proxy's error page, say: keep the first 300 characters.
            return preg_match('/^.{0,300}/us', $text, $match) === 1 ? $match[0] : substr($text, 0, 300);
        }

        if (\is_array($body)) {
            $message = $body['message'] ?? null;
            if (\is_array($message)) {
                $message = implode('; ', array_filter($message, 'is_string'));
            }
            if (\is_string($message) && $message !== '') {
                return $message;
            }
            if (\is_string($body['error'] ?? null) && $body['error'] !== '') {
                return $body['error'];
            }
        }

        return self::fallbackMessage($statusCode);
    }

    private static function fallbackMessage(int $statusCode): string
    {
        return \sprintf('SMSTunnel returned error: %d', $statusCode);
    }
}
