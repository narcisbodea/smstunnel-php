<?php

declare(strict_types=1);

namespace SmsTunnel\Response;

use SmsTunnel\Internal\Fields;

/**
 * What the account used today, and what is left. A client of a company account shares the company's quota
 * and sees it here.
 */
final class Usage
{
    /**
     * @param array<string, int|string>      $usage      e.g. `['smsClean' => 15, 'smsBranded' => 0, 'twoFaClean' => 4]`
     * @param array<string, int|string>      $limits     e.g. `['smsClean' => 'unlimited', 'twoFaClean' => 100]`
     * @param array<string, int|string>      $remaining  same keys as $limits
     * @param array{id: string, name: string}|null $enterprise the company, for a client of a company account
     * @param array<string, mixed>           $raw        the whole `data` object
     */
    public function __construct(
        public readonly string $plan,
        public readonly string $accountType,
        public readonly array $usage = [],
        public readonly array $limits = [],
        public readonly array $remaining = [],
        public readonly ?array $enterprise = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the `data` object of `GET /api/v1/account/usage`
     */
    public static function fromArray(array $data): self
    {
        $enterprise = Fields::object($data, 'enterprise');

        return new self(
            plan: Fields::string($data, 'plan'),
            accountType: Fields::string($data, 'accountType'),
            usage: Fields::counters($data, 'usage'),
            limits: Fields::counters($data, 'limits'),
            remaining: Fields::counters($data, 'remaining'),
            enterprise: $enterprise === [] ? null : [
                'id' => Fields::string($enterprise, 'id'),
                'name' => Fields::string($enterprise, 'name'),
            ],
            raw: $data,
        );
    }
}
