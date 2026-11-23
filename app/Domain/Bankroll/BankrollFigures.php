<?php

namespace App\Domain\Bankroll;

use App\Domain\Currency;

/**
 * The figures the bankroll shows for one bookmaker or in total, in minor units of
 * `$currency`. ROI is an unrounded decimal fraction ("0.057" = 5.7 %), null without
 * turnover. See "Bankroll" in docs/spec.md for the definitions.
 */
final readonly class BankrollFigures
{
    public function __construct(
        public Currency $currency,
        /** Settled and open bets. */
        public int $bets,
        public int $openBets,
        /** Stakes of open cash bets. */
        public int $openStake,
        /** Stakes of settled cash bets. */
        public int $turnover,
        /** Profit of settled bets, free bets included. */
        public int $profit,
        /** Profit ÷ turnover. */
        public ?string $roi,
        /** Deposits − withdrawals + profit − open stake (see the spec for the total). */
        public int $balance,
    ) {}
}
