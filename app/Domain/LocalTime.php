<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The one place that converts between stored UTC instants and a user's local time.
 *
 * Everything stored is a UTC instant; this class turns an instant into local time for
 * display and for "which local day is this", and a local date and time typed by the user
 * into a UTC instant. It never reads the authenticated user: build it from a zone name
 * (`User::localTime()`).
 *
 * Calendar dates that are not instants (statistics days, profit-over-time periods, the
 * bankroll's `occurred_on`) are CarbonImmutable values at 00:00 UTC, like every other
 * calendar date in the app; `today()` and `day()` return local days in that form.
 *
 * Daylight saving (e.g. Europe/Copenhagen):
 * - A local time that doesn't exist (the spring-forward gap, 2026-03-29 02:30) moves
 *   forward by the length of the gap (03:30 +02:00), as PHP and Carbon do.
 * - A local time that occurs twice (the autumn fold, 2026-10-25 02:30) is the first of
 *   the two instants, still in summer time (02:30 +02:00 = 00:30 UTC). This is decided
 *   here rather than left to PHP, whose choice has changed between versions.
 * - A local day starts at local midnight and ends at the next local midnight, so days on
 *   which the clocks change are 23 or 25 hours long. Day bounds never add 24 hours.
 */
final readonly class LocalTime
{
    public DateTimeZone $zone;

    /**
     * @param  string  $timezone  an IANA identifier from identifiers(), e.g. "Europe/Copenhagen"
     *
     * @throws InvalidArgumentException when it isn't one
     */
    public function __construct(string $timezone)
    {
        if (! in_array($timezone, self::identifiers(), true)) {
            throw new InvalidArgumentException("Unknown time zone \"{$timezone}\".");
        }

        $this->zone = new DateTimeZone($timezone);
    }

    public static function utc(): self
    {
        return new self('UTC');
    }

    /**
     * Every time zone a user can choose: exactly what Laravel's `timezone` rule accepts
     * (`timezone:all`), without the legacy aliases such as Europe/Kiev.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * A zone name for people: "America/Argentina/Buenos_Aires" reads
     * "America/Argentina/Buenos Aires".
     */
    public static function label(string $timezone): string
    {
        return str_replace('_', ' ', $timezone);
    }

    public function name(): string
    {
        return $this->zone->getName();
    }

    /**
     * The instant as local time, for display and for the local day it falls on.
     */
    public function toLocal(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->zone);
    }

    /**
     * The UTC instant of a local date (Y-m-d) and time (H:i), resolving daylight-saving
     * gaps and folds as described on the class.
     *
     * @throws InvalidArgumentException when the date or time isn't in that format
     */
    public function toUtc(string $date, string $time = '00:00'): CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== "{$date} {$time}") {
            throw new InvalidArgumentException("\"{$date} {$time}\" is not a date (Y-m-d) and time (H:i).");
        }

        $wallClock = CarbonImmutable::instance($parsed);

        $candidates = [];

        foreach ($this->offsetsAround($wallClock) as $offset) {
            $instant = $wallClock->subSeconds($offset);

            if ($this->offsetAt($instant) === $offset) {
                $candidates[] = $instant;
            }
        }

        if ($candidates !== []) {
            usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

            return $candidates[0];
        }

        // In a gap: read the time with the offset from before the change, which moves it
        // forward by the length of the gap.
        return $wallClock->subSeconds($this->offsetAt($wallClock->subDay()));
    }

    /**
     * The current local time.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone);
    }

    /**
     * Today in the user's zone, as a calendar date (00:00 UTC).
     */
    public function today(): CarbonImmutable
    {
        return $this->day(CarbonImmutable::now());
    }

    /**
     * The local day an instant falls on, as a calendar date (00:00 UTC).
     */
    public function day(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($this->toLocal($instant)->toDateString(), 'UTC');
    }

    /**
     * The UTC instant at which a calendar date starts in the user's zone (local midnight).
     * The day ends where the next one starts: dayStart($day->addDay()).
     */
    public function dayStart(DateTimeInterface $day): CarbonImmutable
    {
        return $this->toUtc($day->format('Y-m-d'));
    }

    /**
     * The UTC offsets in seconds that the zone uses within a day of the wall-clock time.
     *
     * @return list<int>
     */
    private function offsetsAround(CarbonImmutable $wallClock): array
    {
        $transitions = $this->zone->getTransitions($wallClock->subDays(2)->getTimestamp(), $wallClock->addDays(2)->getTimestamp());
        $offsets = array_map(fn (array $transition): int => $transition['offset'], $transitions);

        return array_values(array_unique([...$offsets, $this->offsetAt($wallClock)]));
    }

    private function offsetAt(CarbonImmutable $instant): int
    {
        return $this->zone->getOffset($instant);
    }
}
