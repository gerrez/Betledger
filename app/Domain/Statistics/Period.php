<?php

namespace App\Domain\Statistics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the profit-over-time series groups settled bets by. Weeks start on Monday.
 */
enum Period: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The longest range, in days, still grouped by day. */
    public const MAX_DAYS_BY_DAY = 31;

    /** The longest range, in days, still grouped by week (26 weeks). */
    public const MAX_DAYS_BY_WEEK = 182;

    /**
     * The period for a date range (both days included): by day up to a month, by week
     * up to half a year, else by month.
     */
    public static function forRange(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $days = $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        return match (true) {
            $days <= self::MAX_DAYS_BY_DAY => self::Day,
            $days <= self::MAX_DAYS_BY_WEEK => self::Week,
            default => self::Month,
        };
    }

    /**
     * The start of the period the moment falls in.
     */
    public function start(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Day => $moment->startOfDay(),
            self::Week => $moment->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $moment->startOfMonth(),
        };
    }

    /**
     * The start of the period after the one starting at the given moment.
     */
    public function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Day => $start->addDay(),
            self::Week => $start->addWeek(),
            self::Month => $start->addMonthNoOverflow(),
        };
    }
}
