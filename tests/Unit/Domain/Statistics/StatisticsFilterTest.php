<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\Statistics\StatisticsFilter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StatisticsFilterTest extends TestCase
{
    public function test_dates_are_whole_days(): void
    {
        $filter = new StatisticsFilter(CarbonImmutable::parse('2026-10-01 18:30'), CarbonImmutable::parse('2026-10-21 09:15'));

        $this->assertSame('2026-10-01 00:00:00', $filter->from?->toDateTimeString());
        $this->assertSame('2026-10-21 00:00:00', $filter->to?->toDateTimeString());
    }

    public function test_a_range_of_one_day_is_allowed(): void
    {
        $filter = new StatisticsFilter(CarbonImmutable::parse('2026-10-21 18:00'), CarbonImmutable::parse('2026-10-21 09:00'));

        $this->assertTrue($filter->from?->equalTo($filter->to));
    }

    public function test_the_range_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatisticsFilter(CarbonImmutable::parse('2026-10-21'), CarbonImmutable::parse('2026-10-20'));
    }
}
