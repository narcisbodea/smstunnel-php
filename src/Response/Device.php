<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * A phone linked to your SMSTunnel account.
 */
final class Device
{
    /**
     * @param string               $id         pass it as `deviceId` to send through this phone
     * @param string|null          $lastSeenAt ISO 8601 time
     * @param array<string, mixed> $raw        the whole object
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isOnline,
        public readonly ?string $lastSeenAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data one item of `data.devices` from `GET /api/v1/devices`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Fields::string($data, 'id'),
            name: Fields::string($data, 'name'),
            isOnline: Fields::bool($data, 'isOnline'),
            lastSeenAt: Fields::nullableString($data, 'lastSeenAt'),
            raw: $data,
        );
    }
}
