<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\Statistics\Period;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    /**
     * @return array<string, array{string, string, Period}>
     */
    public static function ranges(): array
    {
        return [
            'one day' => ['2026-10-21', '2026-10-21', Period::Day],
            '31 days' => ['2026-10-01', '2026-10-31', Period::Day],
            '32 days' => ['2026-10-01', '2026-11-01', Period::Week],
            '90 days' => ['2026-07-24', '2026-10-21', Period::Week],
            '182 days' => ['2026-01-01', '2026-07-01', Period::Week],
            '183 days' => ['2026-01-01', '2026-07-02', Period::Month],
            'times of day are ignored' => ['2026-10-01 23:00', '2026-10-31 01:00', Period::Day],
        ];
    }

    #[DataProvider('ranges')]
    public function test_the_period_follows_from_the_length_of_the_range(string $from, string $to, Period $period): void
    {
        $this->assertSame($period, Period::forRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
    }

    public function test_periods_start_at_the_start_of_the_day_the_monday_and_the_first_of_the_month(): void
    {
        $sunday = CarbonImmutable::parse('2026-10-25 18:30');

        $this->assertSame('2026-10-25 00:00:00', Period::Day->start($sunday)->toDateTimeString());
        $this->assertSame('2026-10-19 00:00:00', Period::Week->start($sunday)->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', Period::Month->start($sunday)->toDateTimeString());
    }

    public function test_the_next_period(): void
    {
        $this->assertSame('2026-11-01', Period::Day->next(CarbonImmutable::parse('2026-10-31'))->toDateString());
        $this->assertSame('2026-10-26', Period::Week->next(CarbonImmutable::parse('2026-10-19'))->toDateString());
        $this->assertSame('2027-01-01', Period::Month->next(CarbonImmutable::parse('2026-12-01'))->toDateString());
    }
}
