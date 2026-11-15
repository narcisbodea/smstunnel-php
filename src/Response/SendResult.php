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
     * @param bool                      $replayed  true when SMSTunnel answered a repeat of the `idempotencyKey` with the first answer (the
     *                                             `Idempotent-Replayed` header): this call sent no new text, the first one did
     */
    public function __construct(
        public readonly string $messageId,
        public readonly bool $queued = false,
        public readonly bool $branded = false,
        public readonly array $remaining = [],
        public readonly array $raw = [],
        public readonly bool $replayed = false,
    ) {
    }

    /**
     * @param array<string, mixed> $body     the decoded answer of `POST /api/v1/sms/send` or `/send-2fa`
     * @param bool                 $replayed whether it came with `Idempotent-Replayed: true`
     */
    public static function fromArray(array $body, bool $replayed = false): self
    {
        $data = Fields::object($body, 'data');

        return new self(
            messageId: Fields::nullableString($body, 'messageId') ?? Fields::string($data, 'messageId'),
            queued: Fields::bool($body, 'queued', Fields::bool($data, 'queued')),
            branded: Fields::bool($data, 'branded'),
            remaining: Fields::counters($data, 'remaining'),
            raw: $body,
            replayed: $replayed,
        );
    }
}
