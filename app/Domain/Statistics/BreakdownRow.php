<?php

namespace App\Domain\Statistics;

/**
 * One row of a breakdown: a value of the dimension and the totals of the bets under it.
 */
final readonly class BreakdownRow
{
    public function __construct(
        public Group $group,
        public Totals $totals,
    ) {}
}
