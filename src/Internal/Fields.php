<?php

declare(strict_types=1);

namespace SmsTunnel\Internal;

/**
 * Typed reads from a decoded JSON object, tolerant of fields the server leaves out.
 *
 * @internal
 */
final class Fields
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        return self::nullableString($data, $key) ?? $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        return self::nullableInt($data, $key) ?? $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return match (true) {
            \is_int($value) => $value,
            \is_float($value) => (int) $value,
            \is_string($value) && is_numeric($value) => (int) $value,
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? self::stringKeys($value) : [];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $items[] = self::stringKeys($item);
            }
        }

        return $items;
    }

    /**
     * Numbers and words (`'unlimited'`) of a counter map, such as `remaining` or `limits`.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, int|string>
     */
    public static function counters(array $data, string $key): array
    {
        $counters = [];
        foreach (self::object($data, $key) as $name => $value) {
            if (\is_int($value) || \is_string($value)) {
                $counters[$name] = $value;
            } elseif (\is_float($value)) {
                $counters[$name] = (int) $value;
            }
        }

        return $counters;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
