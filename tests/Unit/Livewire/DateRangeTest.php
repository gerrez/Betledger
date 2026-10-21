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
            'this year' => [DateRange::ThisYear, '2026-01-01', '2026-10-21'],
            'all time' => [DateRange::AllTime, null, null],
            'custom' => [DateRange::Custom, null, null],
        ];
    }

    #[DataProvider('ranges')]
    public function test_rolling_ranges_end_today_and_include_it(DateRange $range, ?string $from, ?string $to): void
    {
        [$first, $last] = $range->days(CarbonImmutable::parse('2026-10-21 15:00'));

        $this->assertSame($from === null ? null : "{$from} 00:00:00", $first?->toDateTimeString());
        $this->assertSame($to === null ? null : "{$to} 00:00:00", $last?->toDateTimeString());
    }
}
