<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * One page of the texts your API key scheduled. Iterate it, or read {@see $items}.
 *
 * @implements \IteratorAggregate<int, ScheduledText>
 */
final class ScheduledTexts implements \IteratorAggregate, \Countable
{
    /** The texts still waiting, soonest first. */
    public const WAITING = 'waiting';
    /** The texts that went out, failed or were canceled in the last 30 days, newest first. */
    public const DONE = 'done';

    /**
     * @param list<ScheduledText>  $items
     * @param int                  $total   the key's texts in this scope, on every page
     * @param int                  $waiting the texts waiting in the whole account, from every key and the dashboard
     * @param int                  $max     how many texts the plan lets wait at once
     * @param array<string, mixed> $raw     the whole `data` object
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
        public readonly int $waiting,
        public readonly int $max,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the `data` object of `GET /api/v1/sms/scheduled`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: array_map(ScheduledText::fromArray(...), Fields::objects($data, 'items')),
            total: Fields::int($data, 'total'),
            page: Fields::int($data, 'page', 1),
            limit: Fields::int($data, 'limit'),
            waiting: Fields::int($data, 'waiting'),
            max: Fields::int($data, 'max'),
            raw: $data,
        );
    }

    /** @return \ArrayIterator<int, ScheduledText> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /** The texts on this page (see {@see $total} for all of them). */
    public function count(): int
    {
        return \count($this->items);
    }
}
