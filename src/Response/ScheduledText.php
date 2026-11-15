<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * A text your app scheduled: `sendSms()` with `sendAt` answers with one, and {@see \SmsTunnel\Client::listScheduled()},
 * {@see \SmsTunnel\Client::getScheduled()} and {@see \SmsTunnel\Client::cancelScheduled()} read them.
 *
 * At {@see $sendAt} SMSTunnel sends it as if your app called `sendSms()` then, with the same API key: the phone, the
 * country code and the day's allowance are those of that moment. Once it went out, {@see $messageId} is set: look up
 * its delivery with {@see \SmsTunnel\Client::getMessageStatus()}.
 */
final class ScheduledText
{
    /** Waiting for its time (and, when the phone is offline then, for the phone, up to 3 days). */
    public const SCHEDULED = 'scheduled';
    /** Handed to the phone at its time: {@see $messageId} is set. */
    public const SENT = 'sent';
    /** Not sent: {@see $error} says why. */
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    /**
     * @param string               $id        keep it to read or cancel the text (the send's `scheduledId`)
     * @param string               $to        the number, as your app gave it
     * @param string               $sendAt    when it goes out, ISO 8601 in UTC, e.g. `2026-11-16T07:00:00.000Z`
     * @param string               $status    `scheduled`, `sent`, `failed` or `canceled`
     * @param string|null          $deviceId  the phone your app named, or null: SMSTunnel picks one at its time
     * @param string|null          $messageId once it went out: the text's id, for {@see \SmsTunnel\Client::getMessageStatus()}
     * @param string|null          $error     why it failed: `limit_reached`, `key_not_active`, `invalid_number`,
     *                                        `phone_not_found`, `phone_unlinked`, `sealed_only_phone`, `number_not_allowed`,
     *                                        `account_not_active`, `interrupted` or `failed`
     * @param string|null          $createdAt ISO 8601 time
     * @param array<string, mixed> $raw       the whole object
     * @param bool                 $replayed  from `sendSms()` with `sendAt` only: true when SMSTunnel answered a repeat of the
     *                                        `idempotencyKey` with the first answer, so this call scheduled no new text
     */
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $message,
        public readonly string $sendAt,
        public readonly string $status = self::SCHEDULED,
        public readonly ?string $deviceId = null,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
        public readonly ?string $createdAt = null,
        public readonly array $raw = [],
        public readonly bool $replayed = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data     one scheduled text: the `data` of `GET /api/v1/sms/scheduled/:id`, or an item of the list
     * @param bool                 $replayed whether the answer came with `Idempotent-Replayed: true`
     */
    public static function fromArray(array $data, bool $replayed = false): self
    {
        return new self(
            id: Fields::string($data, 'id'),
            to: Fields::string($data, 'to'),
            message: Fields::string($data, 'message'),
            sendAt: Fields::string($data, 'sendAt'),
            status: Fields::string($data, 'status', self::SCHEDULED),
            deviceId: Fields::nullableString($data, 'deviceId'),
            messageId: Fields::nullableString($data, 'messageId'),
            error: Fields::nullableString($data, 'error'),
            createdAt: Fields::nullableString($data, 'createdAt'),
            raw: $data,
            replayed: $replayed,
        );
    }
}
