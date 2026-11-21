<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * Where a text is now.
 */
final class MessageStatus
{
    public const PENDING = 'pending';
    /** The phone sent it. The last good status today: the app gets no delivery reports, so a text that went out stays `sent`. */
    public const SENT = 'sent';
    /** Reserved for a delivery report. The app gets none today, so the texts you send don't reach this status. */
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    /** Handed to the SIM, but the phone got no confirmation. Final: SMSTunnel never retries it. */
    public const UNCONFIRMED = 'unconfirmed';

    /**
     * @param string               $status         `pending`, `sent`, `delivered` or `failed` (an unconfirmed text shows as `failed`)
     * @param string|null          $deliveryStatus the real state, which can also be `unconfirmed`
     * @param bool                 $unconfirmed    true when the phone handed the text to the SIM but got no confirmation
     * @param string|null          $sentAt         ISO 8601 time
     * @param string|null          $deliveredAt    reserved, like `delivered`: null for the texts you send today
     * @param array<string, mixed> $raw            the whole `data` object
     */
    public function __construct(
        public readonly string $id,
        public readonly string $phoneNumber,
        public readonly string $status,
        public readonly ?string $deliveryStatus = null,
        public readonly bool $unconfirmed = false,
        public readonly ?string $sentAt = null,
        public readonly ?string $deliveredAt = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the `data` object of `GET /api/v1/sms/status/:id`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Fields::string($data, 'id'),
            phoneNumber: Fields::string($data, 'phoneNumber'),
            status: Fields::string($data, 'status', self::PENDING),
            deliveryStatus: Fields::nullableString($data, 'deliveryStatus'),
            unconfirmed: Fields::bool($data, 'unconfirmed'),
            sentAt: Fields::nullableString($data, 'sentAt'),
            deliveredAt: Fields::nullableString($data, 'deliveredAt'),
            errorMessage: Fields::nullableString($data, 'errorMessage'),
            raw: $data,
        );
    }

    /**
     * True once there is nothing more to wait for: sent, delivered, failed or unconfirmed.
     * A text the phone sent stays `sent`, because the app gets no delivery reports.
     */
    public function isFinal(): bool
    {
        return \in_array($this->deliveryStatus ?? $this->status, [self::SENT, self::DELIVERED, self::FAILED, self::UNCONFIRMED], true);
    }
}
