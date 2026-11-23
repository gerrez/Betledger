<?php

namespace App\Domain\Bets;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When a bet is decided, which is the date statistics count it on (`bets.event_at`): the
 * latest start among its selections (an accumulator is decided by its last leg), else the
 * time it was placed, and never before it was placed.
 *
 * A Sportmarket import has no start times, only a game date, so an imported bet uses the
 * instant it was imported with instead of the placed date. That instant stays the bet's
 * event date when it is edited, unless the user gives a selection a start time.
 */
final class EventDate
{
    /**
     * @param  list<DateTimeInterface|null>  $startTimes  the selections' start times, null when one has none
     * @param  DateTimeInterface|null  $importedAt  for an imported bet: the instant it was imported with (its game date)
     */
    public static function forBet(DateTimeInterface $placedAt, array $startTimes, ?DateTimeInterface $importedAt = null): CarbonImmutable
    {
        $placed = CarbonImmutable::instance($placedAt);
        $latestStart = null;

        foreach ($startTimes as $startTime) {
            if ($startTime !== null && ($latestStart === null || $startTime > $latestStart)) {
                $latestStart = CarbonImmutable::instance($startTime);
            }
        }

        $event = $latestStart ?? ($importedAt === null ? $placed : CarbonImmutable::instance($importedAt));

        return $event->max($placed);
    }

    /**
     * An imported bet without start times, decided at the given instant (its game date).
     */
    public static function forImport(DateTimeInterface $placedAt, DateTimeInterface $decidedAt): CarbonImmutable
    {
        return self::forBet($placedAt, [], $decidedAt);
    }
}
