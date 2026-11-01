<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * SMSTunnel accepted the text and handed it to your phone (or will, as soon as the phone is back online).
 */
final class SendResult
{
    /**
     * @param string                    $messageId use it with {@see \SmsTunnel\Client::getMessageStatus()}
     * @param bool                      $queued    true when the phone was offline: it sends the text when it is back
     * @param bool                      $branded   true when the text carries a short SMSTunnel signature (plans with a free quota)
     * @param array<string, int|string> $remaining what is left of today's quota, e.g. `['clean' => 95, 'total' => 'unlimited']` (`-1` means no limit)
     * @param array<string, mixed>      $raw       the whole answer, for fields this version does not map yet
     */
    public function __construct(
        public readonly string $messageId,
        public readonly bool $queued = false,
        public readonly bool $branded = false,
        public readonly array $remaining = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body the decoded answer of `POST /api/v1/sms/send` or `/send-2fa`
     */
    public static function fromArray(array $body): self
    {
        $data = Fields::object($body, 'data');

        return new self(
            messageId: Fields::nullableString($body, 'messageId') ?? Fields::string($data, 'messageId'),
            queued: Fields::bool($body, 'queued', Fields::bool($data, 'queued')),
            branded: Fields::bool($data, 'branded'),
            remaining: Fields::counters($data, 'remaining'),
            raw: $body,
        );
    }
}
