<?php

namespace App\Domain\Settlement;

use App\Domain\Odds;

/**
 * One selection of a bet as far as settlement is concerned: its odds and its result
 * (null while pending).
 */
final readonly class Leg
{
    public string $odds;

    public function __construct(string $odds, public ?SelectionResult $result = null)
    {
        $this->odds = Odds::assertValid($odds);
    }

    public function isPending(): bool
    {
        return $this->result === null;
    }

    public function factor(): ?string
    {
        return $this->result?->factor($this->odds);
    }
}
