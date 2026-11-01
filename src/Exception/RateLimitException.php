<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * 429: too many requests in a short time (sending allows 30 requests a minute), or a daily limit is reached.
 */
class RateLimitException extends ApiException
{
    /** Seconds to wait before trying again, when SMSTunnel says (the `Retry-After` header). */
    public function getRetryAfter(): ?int
    {
        $value = $this->getHeaders()['retry-after'] ?? null;
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}
