<?php

namespace App\Domain\Statistics;

use Carbon\CarbonImmutable;

/**
 * A fall of cumulative profit from an earlier peak, in minor units of the base currency.
 */
final readonly class Drawdown
{
    /**
     * @param  int  $amount  how far cumulative profit fell below the peak, always above 0
     * @param  CarbonImmutable|null  $peakAt  event date of the bet that last reached the peak; null when the peak is the start (0, before the first bet)
     * @param  CarbonImmutable  $lowAt  event date of the bet at the low; for the current drawdown, the last settled bet
     */
    public function __construct(
        public int $amount,
        public ?CarbonImmutable $peakAt,
        public CarbonImmutable $lowAt,
    ) {}
}
