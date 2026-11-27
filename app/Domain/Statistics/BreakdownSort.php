<?php

namespace App\Domain\Statistics;

use App\Domain\Decimal;

/**
 * The columns the breakdown table can be sorted by. A row without a value for the column
 * (no ROI or strike rate because there is nothing to divide by, no CLV because none of
 * its bets has closing odds) has nothing to rank by; the calculator puts it last in
 * either direction.
 */
enum BreakdownSort: string
{
    /** Settled bets. */
    case Bets = 'bets';
    case Turnover = 'turnover';
    case Profit = 'profit';
    case Roi = 'roi';
    case StrikeRate = 'strike';
    /** Average CLV. */
    case Clv = 'clv';

    /**
     * The column's heading in the breakdown table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bets => 'Bets',
            self::Turnover => 'Turnover',
            self::Profit => 'Profit',
            self::Roi => 'ROI',
            self::StrikeRate => 'Strike',
            self::Clv => 'CLV',
        };
    }

    /**
     * The row's value for the column: a count or minor units, a decimal fraction, or null
     * when it has none.
     */
    public function valueOf(Totals $totals): int|string|null
    {
        return match ($this) {
            self::Bets => $totals->settledBets,
            self::Turnover => $totals->turnover,
            self::Profit => $totals->profit,
            self::Roi => $totals->roi,
            self::StrikeRate => $totals->strikeRate,
            self::Clv => $totals->averageClv,
        };
    }

    /**
     * Compare two values of the column, lowest first: integers as integers, decimal
     * fractions exactly.
     */
    public function compare(int|string $a, int|string $b): int
    {
        return is_string($a) && is_string($b) ? Decimal::compare($a, $b) : $a <=> $b;
    }
}
