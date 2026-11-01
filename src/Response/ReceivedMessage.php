<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * A text your phone received and synced to your account.
 */
final class ReceivedMessage
{
    /**
     * @param string               $sender           the number that sent it
     * @param string|null          $message          the words; null when the phone synced it encrypted (see $encryptedPayload)
     * @param string               $receivedAt       ISO 8601 time
     * @param array<string, mixed> $raw              the whole object
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sender,
        public readonly ?string $message,
        public readonly string $receivedAt,
        public readonly bool $isEncrypted = false,
        public readonly ?string $encryptedPayload = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $deviceName = null,
        public readonly ?int $simSlot = null,
        public readonly ?int $subscriptionId = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data one item of `data.messages` from `GET /api/v1/sms/received`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Fields::string($data, 'id'),
            sender: Fields::string($data, 'sender'),
            message: Fields::nullableString($data, 'message'),
            receivedAt: Fields::string($data, 'receivedAt'),
            isEncrypted: Fields::bool($data, 'isEncrypted'),
            encryptedPayload: Fields::nullableString($data, 'encryptedPayload'),
            deviceId: Fields::nullableString($data, 'deviceId'),
            deviceName: Fields::nullableString($data, 'deviceName'),
            simSlot: Fields::nullableInt($data, 'simSlot'),
            subscriptionId: Fields::nullableInt($data, 'subscriptionId'),
            raw: $data,
        );
    }
}
