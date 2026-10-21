<?php

namespace App\Domain\Statistics;

use Carbon\CarbonImmutable;

/**
 * One point of the profit-over-time series: the profit of the bets settled in a period
 * and the cumulative profit up to its end, in minor units of the base currency.
 */
final readonly class ProfitPoint
{
    public function __construct(
        public CarbonImmutable $start,
        public int $profit,
        public int $cumulativeProfit,
    ) {}
}
