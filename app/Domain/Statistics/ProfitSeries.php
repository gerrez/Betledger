<?php

namespace App\Domain\Statistics;

/**
 * Cumulative profit over time, one point per period from the first to the last.
 */
final readonly class ProfitSeries
{
    /**
     * @param  list<ProfitPoint>  $points
     */
    public function __construct(
        public Period $period,
        public array $points,
    ) {}
}
