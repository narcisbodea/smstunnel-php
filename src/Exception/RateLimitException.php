<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * 429: too many requests in a short time (sending allows 30 requests a minute), or a daily limit is reached.
 * {@see getRetryAfter()} says how long to wait after too many requests; it is null for a daily limit.
 */
class RateLimitException extends ApiException
{
}
