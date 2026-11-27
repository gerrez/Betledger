<?php

namespace App\Domain\Statistics;

/**
 * How bumpy the road was for a set of bets: drawdowns, streaks, the biggest single
 * results and the average stake, in minor units of the base currency. Worked out over
 * the settled bets in event order (ties by bet id); see Statistics in docs/spec.md.
 */
final readonly class RiskFigures
{
    public function __construct(
        /** The largest fall of cumulative profit from an earlier peak; null when it never fell. */
        public ?Drawdown $maxDrawdown,
        /** How far cumulative profit is below its peak after the last bet; null at the peak. */
        public ?Drawdown $currentDrawdown,
        /** Most winning bets in a row (0 without wins). */
        public int $longestWinningStreak,
        /** Most losing bets in a row (0 without losses). */
        public int $longestLosingStreak,
        /** The streak the last win or loss is part of; null without wins or losses. */
        public ?Streak $currentStreak,
        /** The settled bet with the highest profit above 0, the earliest on a tie. */
        public ?BetFigures $biggestWin,
        /** The settled bet with the lowest profit below 0, the earliest on a tie. */
        public ?BetFigures $biggestLoss,
        /** Turnover ÷ settled cash bets, rounded half up to minor units; null without them. */
        public ?int $averageStake,
    ) {}
}
