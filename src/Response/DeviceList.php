<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * The phones of the API key's account. Iterate it, or read {@see $devices}.
 *
 * @implements \IteratorAggregate<int, Device>
 */
final class DeviceList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Device>         $devices
     * @param array<string, mixed> $raw     the whole `data` object
     */
    public function __construct(
        public readonly array $devices,
        public readonly int $online,
        public readonly int $total,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the `data` object of `GET /api/v1/devices`
     */
    public static function fromArray(array $data): self
    {
        $devices = array_map(Device::fromArray(...), Fields::objects($data, 'devices'));
        $online = \count(array_filter($devices, static fn (Device $device): bool => $device->isOnline));

        return new self(
            devices: $devices,
            online: Fields::int($data, 'online', $online),
            total: Fields::int($data, 'total', \count($devices)),
            raw: $data,
        );
    }

    /** The phone with this id, or null. */
    public function find(string $id): ?Device
    {
        foreach ($this->devices as $device) {
            if ($device->id === $id) {
                return $device;
            }
        }

        return null;
    }

    /** @return \ArrayIterator<int, Device> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->devices);
    }

    public function count(): int
    {
        return \count($this->devices);
    }
}
