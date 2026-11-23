<?php

namespace Tests\Unit\Domain;

use App\Domain\LocalTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Converting between stored UTC instants and local time, with Copenhagen's daylight
 * saving: +01:00 in winter, +02:00 from 2026-03-29 01:00 UTC to 2026-10-25 01:00 UTC.
 */
class LocalTimeTest extends TestCase
{
    private const COPENHAGEN = 'Europe/Copenhagen';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function localTimes(): array
    {
        return [
            'winter' => ['2026-01-15', '12:00', '2026-01-15T11:00:00Z'],
            'summer' => ['2026-07-01', '00:30', '2026-06-30T22:30:00Z'],
            'just before the spring gap' => ['2026-03-29', '01:59', '2026-03-29T00:59:00Z'],
            'just after the spring gap' => ['2026-03-29', '03:00', '2026-03-29T01:00:00Z'],
            'just after the autumn fold' => ['2026-10-25', '03:00', '2026-10-25T02:00:00Z'],
        ];
    }

    #[DataProvider('localTimes')]
    public function test_a_local_time_becomes_a_utc_instant_and_back(string $date, string $time, string $utc): void
    {
        $localTime = new LocalTime(self::COPENHAGEN);

        $instant = $localTime->toUtc($date, $time);

        $this->assertSame($utc, $instant->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame("{$date} {$time}", $localTime->toLocal($instant)->format('Y-m-d H:i'));
    }

    public function test_a_local_time_in_the_spring_gap_moves_forward(): void
    {
        $instant = (new LocalTime(self::COPENHAGEN))->toUtc('2026-03-29', '02:30');

        $this->assertSame('2026-03-29 01:30', $instant->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-03-29 03:30 +02:00', (new LocalTime(self::COPENHAGEN))->toLocal($instant)->format('Y-m-d H:i P'));
    }

    public function test_a_local_time_in_the_autumn_fold_is_its_first_occurrence(): void
    {
        $instant = (new LocalTime(self::COPENHAGEN))->toUtc('2026-10-25', '02:30');

        $this->assertSame('2026-10-25 00:30', $instant->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 02:30 +02:00', (new LocalTime(self::COPENHAGEN))->toLocal($instant)->format('Y-m-d H:i P'));
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function days(): array
    {
        return [
            'a normal winter day' => ['2026-11-22', '2026-11-21 23:00', 24],
            'a normal summer day' => ['2026-07-01', '2026-06-30 22:00', 24],
            'the 23-hour spring day' => ['2026-03-29', '2026-03-28 23:00', 23],
            'the 25-hour autumn day' => ['2026-10-25', '2026-10-24 22:00', 25],
        ];
    }

    #[DataProvider('days')]
    public function test_a_local_day_runs_from_local_midnight_to_the_next(string $day, string $startUtc, int $hours): void
    {
        $localTime = new LocalTime(self::COPENHAGEN);
        $date = CarbonImmutable::parse($day, 'UTC');

        $start = $localTime->dayStart($date);
        $end = $localTime->dayStart($date->addDay());

        $this->assertSame($startUtc, $start->utc()->format('Y-m-d H:i'));
        $this->assertSame($hours, (int) $start->diffInHours($end));
    }

    public function test_the_local_day_of_an_instant(): void
    {
        $localTime = new LocalTime(self::COPENHAGEN);

        $this->assertSame('2026-11-23', $localTime->day(CarbonImmutable::parse('2026-11-22 23:30', 'UTC'))->toDateString());
        $this->assertSame('2026-11-22', $localTime->day(CarbonImmutable::parse('2026-11-22 22:59', 'UTC'))->toDateString());
        // Winter is +01:00: 22:30 UTC on 30 November is still 30 November, 23:30 UTC isn't.
        $this->assertSame('2026-11-30', $localTime->day(CarbonImmutable::parse('2026-11-30 22:30', 'UTC'))->toDateString());
        $this->assertSame('2026-12-01', $localTime->day(CarbonImmutable::parse('2026-11-30 23:30', 'UTC'))->toDateString());
        // Summer is +02:00: 22:30 UTC on 31 August is already 1 September.
        $this->assertSame('2026-09-01', $localTime->day(CarbonImmutable::parse('2026-08-31 22:30', 'UTC'))->toDateString());
        $this->assertSame('UTC', $localTime->day(CarbonImmutable::parse('2026-11-22 23:30', 'UTC'))->getTimezone()->getName());
    }

    public function test_utc_converts_nothing(): void
    {
        $localTime = LocalTime::utc();
        $instant = CarbonImmutable::parse('2026-11-22 23:30', 'UTC');

        $this->assertSame('2026-11-22 23:30', $localTime->toUtc('2026-11-22', '23:30')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-22 23:30', $localTime->toLocal($instant)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-22', $localTime->day($instant)->toDateString());
        $this->assertSame('2026-11-22 00:00', $localTime->dayStart(CarbonImmutable::parse('2026-11-22'))->format('Y-m-d H:i'));
    }

    public function test_unknown_and_legacy_zone_names_are_refused(): void
    {
        foreach (['Mars/Olympus_Mons', 'Europe/Kiev', ''] as $name) {
            try {
                new LocalTime($name);
                $this->fail("{$name} was accepted.");
            } catch (InvalidArgumentException) {
                $this->assertNotContains($name, LocalTime::identifiers());
            }
        }
    }

    public function test_an_invalid_local_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LocalTime(self::COPENHAGEN))->toUtc('2026-02-30', '12:00');
    }

    public function test_zone_names_read_with_spaces(): void
    {
        $this->assertSame('America/Argentina/Buenos Aires', LocalTime::label('America/Argentina/Buenos_Aires'));
    }
}
