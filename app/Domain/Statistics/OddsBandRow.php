<?php

namespace App\Domain\Statistics;

/**
 * The totals of the bets whose total odds are in a band.
 */
final readonly class OddsBandRow
{
    public function __construct(
        public OddsBand $band,
        public Totals $totals,
    ) {}
}
