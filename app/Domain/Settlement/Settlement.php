<?php

namespace App\Domain\Settlement;

/**
 * The outcome of settling a bet. Payout and profit are in minor units of the bet's
 * currency; the factor is the exact (unrounded) bet factor.
 */
final readonly class Settlement
{
    public function __construct(
        public string $factor,
        public int $payout,
        public int $profit,
    ) {}
}
