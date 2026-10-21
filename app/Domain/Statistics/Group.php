<?php

namespace App\Domain\Statistics;

/**
 * One value a bet counts under in a breakdown: a bookmaker, sport, tag, … (key = its id),
 * a bet type (key = the BetType value), or none (a bet without a tipster, tags,
 * competition, market or teams), whose key and label are null.
 */
final readonly class Group
{
    public function __construct(
        public int|string|null $key,
        public ?string $label,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public function isNone(): bool
    {
        return $this->key === null;
    }
}
