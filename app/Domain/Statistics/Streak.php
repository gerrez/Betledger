<?php

namespace App\Domain\Statistics;

/**
 * Consecutive winning or losing bets in event order; pushes neither extend nor break it.
 */
final readonly class Streak
{
    public function __construct(
        public bool $isWinning,
        public int $length,
    ) {}
}
