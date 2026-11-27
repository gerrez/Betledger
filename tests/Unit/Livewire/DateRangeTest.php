<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Statistics\DateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    /**
     * Relative to Wednesday 21 October 2026, 15:00.
     *
     * @return array<string, array{DateRange, string|null, string|null}>
     */
    public static function ranges(): array
    {
        return [
            '30 days' => [DateRange::Last30Days, '2026-09-22', '2026-10-21'],
            '90 days' => [DateRange::Last90Days, '2026-07-24', '2026-10-21'],
            'this month' => [DateRange::ThisMonth, '2026-10-01', '2026-10-21'],
            'last month' => [DateRange::LastMonth, '2026-09-01', '2026-09-30'],
            'this year' => [DateRange::ThisYear, '2026-01-01', '2026-10-21'],
            'last year' => [DateRange::LastYear, '2025-01-01', '2025-12-31'],
            'all time' => [DateRange::AllTime, null, null],
            'custom' => [DateRange::Custom, null, null],
        ];
    }

    #[DataProvider('ranges')]
    public function test_each_range_has_its_first_and_last_day(DateRange $range, ?string $from, ?string $to): void
    {
        [$first, $last] = $range->days(CarbonImmutable::parse('2026-10-21 15:00'));

        $this->assertSame($from === null ? null : "{$from} 00:00:00", $first?->toDateTimeString());
        $this->assertSame($to === null ? null : "{$to} 00:00:00", $last?->toDateTimeString());
    }

    /**
     * @return array<string, array{string, DateRange, string, string}>
     */
    public static function calendarEdges(): array
    {
        return [
            'last month from 31 March is all of February' => ['2026-03-31', DateRange::LastMonth, '2026-02-01', '2026-02-28'],
            'last month in a leap year' => ['2028-03-31', DateRange::LastMonth, '2028-02-01', '2028-02-29'],
            'last month in January is December' => ['2027-01-15', DateRange::LastMonth, '2026-12-01', '2026-12-31'],
            'this month on its first day is that day' => ['2026-11-01', DateRange::ThisMonth, '2026-11-01', '2026-11-01'],
            'last year on 1 January' => ['2027-01-01', DateRange::LastYear, '2026-01-01', '2026-12-31'],
        ];
    }

    #[DataProvider('calendarEdges')]
    public function test_calendar_ranges_at_month_and_year_edges(string $today, DateRange $range, string $from, string $to): void
    {
        [$first, $last] = $range->days(CarbonImmutable::parse("{$today} 23:30"));

        $this->assertSame("{$from} 00:00:00", $first?->toDateTimeString());
        $this->assertSame("{$to} 00:00:00", $last?->toDateTimeString());
    }
}
