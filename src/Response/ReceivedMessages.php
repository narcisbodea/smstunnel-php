<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * One page of received texts, newest first. Iterate it, or read {@see $messages}.
 *
 * @implements \IteratorAggregate<int, ReceivedMessage>
 */
final class ReceivedMessages implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ReceivedMessage> $messages
     * @param int                   $total    all texts that match the filters, on every page
     * @param array<string, mixed>  $raw      the whole `data` object
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
        public readonly bool $hasMore,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the `data` object of `GET /api/v1/sms/received`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messages: array_map(ReceivedMessage::fromArray(...), Fields::objects($data, 'messages')),
            total: Fields::int($data, 'total'),
            page: Fields::int($data, 'page', 1),
            limit: Fields::int($data, 'limit'),
            hasMore: Fields::bool($data, 'hasMore'),
            raw: $data,
        );
    }

    /** @return \ArrayIterator<int, ReceivedMessage> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->messages);
    }

    /** The texts on this page (see {@see $total} for all of them). */
    public function count(): int
    {
        return \count($this->messages);
    }
}
