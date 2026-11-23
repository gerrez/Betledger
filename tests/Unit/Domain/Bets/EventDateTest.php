<?php

namespace Tests\Unit\Domain\Bets;

use App\Domain\Bets\EventDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class EventDateTest extends TestCase
{
    public function test_a_single_counts_on_its_start(): void
    {
        $this->assertSame('2026-10-24 15:00:00', $this->eventDate('2026-10-20 12:00', ['2026-10-24 15:00']));
    }

    public function test_a_single_without_a_start_counts_on_its_placed_date(): void
    {
        $this->assertSame('2026-10-20 12:00:00', $this->eventDate('2026-10-20 12:00', [null]));
        $this->assertSame('2026-10-20 12:00:00', $this->eventDate('2026-10-20 12:00', []));
    }

    public function test_an_accumulator_counts_on_its_last_start(): void
    {
        $this->assertSame('2026-10-26 20:00:00', $this->eventDate('2026-10-20 12:00', ['2026-10-24 15:00', '2026-10-26 20:00', '2026-10-25 18:00']));
    }

    public function test_an_accumulator_ignores_legs_without_a_start(): void
    {
        $this->assertSame('2026-10-24 15:00:00', $this->eventDate('2026-10-20 12:00', [null, '2026-10-24 15:00', null]));
    }

    public function test_the_event_date_is_never_before_the_placed_date(): void
    {
        $this->assertSame('2026-10-20 12:00:00', $this->eventDate('2026-10-20 12:00', ['2026-10-19 18:00']));
    }

    public function test_an_import_counts_on_the_instant_it_was_imported_with(): void
    {
        $placed = CarbonImmutable::parse('2026-10-20 12:00');

        $this->assertSame('2026-10-21 23:59:00', EventDate::forImport($placed, CarbonImmutable::parse('2026-10-21 23:59'))->toDateTimeString());
        $this->assertSame('2026-10-20 12:00:00', EventDate::forImport($placed, CarbonImmutable::parse('2026-10-19 23:59'))->toDateTimeString());
    }

    public function test_a_start_time_takes_precedence_over_the_imported_instant(): void
    {
        $eventAt = EventDate::forBet(CarbonImmutable::parse('2026-10-20 12:00'), [CarbonImmutable::parse('2026-10-21 19:45')], CarbonImmutable::parse('2026-10-21 23:59'));

        $this->assertSame('2026-10-21 19:45:00', $eventAt->toDateTimeString());
    }

    /**
     * @param  list<string|null>  $startTimes
     */
    private function eventDate(string $placedAt, array $startTimes): string
    {
        return EventDate::forBet(
            CarbonImmutable::parse($placedAt),
            array_map(fn (?string $start): ?CarbonImmutable => $start === null ? null : CarbonImmutable::parse($start), $startTimes),
        )->toDateTimeString();
    }
}
