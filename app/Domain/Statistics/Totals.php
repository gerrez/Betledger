<?php

namespace App\Domain\Statistics;

/**
 * The totals of a set of bets, in minor units of the base currency. Ratios (ROI, strike
 * rate, CLV) are unrounded decimal fractions ("0.057" = 5.7 %), null when there is
 * nothing to divide by.
 */
final readonly class Totals
{
    public function __construct(
        public int $settledBets,
        public int $openBets,
        /** Stakes of settled cash bets (free bet stakes aren't money risked). */
        public int $turnover,
        /** Profit of settled bets, free bets included when they are in the set. */
        public int $profit,
        /** Profit ÷ turnover. */
        public ?string $roi,
        /** Winning bets ÷ winning and losing bets (see BetFigures::isWin() and isLoss()); void bets don't count. */
        public ?string $strikeRate,
        /** Settled bets that count as a win in the strike rate. */
        public int $winningBets,
        /** Settled bets that count as a loss in the strike rate. */
        public int $losingBets,
        /** Mean total odds of settled bets. */
        public ?string $averageOdds,
        /** Mean CLV of the settled bets with closing odds on every selection. */
        public ?string $averageClv,
        /** Settled bets with closing odds on every selection. */
        public int $clvBets,
        /** Of those, the bets whose CLV is above 0. */
        public int $beatCloseBets,
        /** Beat-the-close bets ÷ bets with CLV. */
        public ?string $beatCloseRate,
        /** Stakes of open cash bets. */
        public int $openStake,
    ) {}
}
