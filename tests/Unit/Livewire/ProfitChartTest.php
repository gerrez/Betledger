<?php

namespace Tests\Unit\Livewire;

use App\Domain\Currency;
use App\Domain\Statistics\Period;
use App\Domain\Statistics\ProfitPoint;
use App\Domain\Statistics\ProfitSeries;
use App\Livewire\Statistics\ProfitChart;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProfitChartTest extends TestCase
{
    public function test_the_line_starts_at_zero_and_the_value_axis_has_round_steps(): void
    {
        $chart = ProfitChart::fromSeries($this->series(Period::Week, '2026-09-28', [21000, 48000, 39000, 82000]), Currency::Dkk);

        $this->assertNotNull($chart);
        $this->assertSame('Cumulative, by week settled', $chart->caption);
        $this->assertSame('Cumulative profit over 4 weeks, ending at +820.00 kr', $chart->description);
        $this->assertSame([
            ['value' => '1,000', 'y' => '0', 'isZero' => false],
            ['value' => '750', 'y' => '65', 'isZero' => false],
            ['value' => '500', 'y' => '130', 'isZero' => false],
            ['value' => '250', 'y' => '195', 'isZero' => false],
            ['value' => '0', 'y' => '260', 'isZero' => true],
        ], $chart->ticks);
        $this->assertSame('M0 260 L250 205.4 L500 135.2 L750 158.6 L1000 46.8', $chart->line);
        $this->assertSame('M0 260 L250 205.4 L500 135.2 L750 158.6 L1000 46.8 L1000 260 L0 260 Z', $chart->area);
        $this->assertSame([
            ['label' => '28 Sep', 'left' => '25.0%', 'isLast' => false, 'isMinor' => false],
            ['label' => '5 Oct', 'left' => '50.0%', 'isLast' => false, 'isMinor' => true],
            ['label' => '12 Oct', 'left' => '75.0%', 'isLast' => false, 'isMinor' => false],
            ['label' => '19 Oct', 'left' => '100.0%', 'isLast' => true, 'isMinor' => false],
        ], $chart->dates);
    }

    public function test_a_loss_extends_the_axis_below_zero_and_the_area_closes_on_zero(): void
    {
        $chart = ProfitChart::fromSeries($this->series(Period::Day, '2026-10-19', [-3500, -12000, 4100]), Currency::Eur);

        $this->assertNotNull($chart);
        $this->assertSame('Cumulative profit over 3 days, ending at +€41.00', $chart->description);
        $this->assertSame(['50', '0', '−50', '−100', '−150'], array_column($chart->ticks, 'value'));
        $this->assertSame([false, true, false, false, false], array_column($chart->ticks, 'isZero'));
        $this->assertSame('M0 65 L333.3 110.5 L666.7 221 L1000 11.7', $chart->line);
        $this->assertSame('M0 65 L333.3 110.5 L666.7 221 L1000 11.7 L1000 65 L0 65 Z', $chart->area);
    }

    public function test_a_flat_line_at_zero_still_gets_an_axis(): void
    {
        $chart = ProfitChart::fromSeries($this->series(Period::Month, '2026-10-01', [0]), Currency::Eur);

        $this->assertNotNull($chart);
        $this->assertSame('Cumulative profit over 1 month, ending at €0.00', $chart->description);
        $this->assertSame(['4', '3', '2', '1', '0'], array_column($chart->ticks, 'value'));
        $this->assertSame('M0 260 L1000 260', $chart->line);
        $this->assertSame([['label' => 'Oct 2026', 'left' => '100.0%', 'isLast' => true, 'isMinor' => false]], $chart->dates);
    }

    public function test_five_periods_spread_from_the_first_to_the_last_are_labelled(): void
    {
        $cumulative = array_map(fn (int $day): int => $day * 11246, range(1, 31));
        $chart = ProfitChart::fromSeries($this->series(Period::Day, '2026-10-01', $cumulative), Currency::Eur);

        $this->assertNotNull($chart);
        $this->assertSame(['4,000', '3,000', '2,000', '1,000', '0'], array_column($chart->ticks, 'value'));
        $this->assertSame(['1 Oct', '9 Oct', '16 Oct', '24 Oct', '31 Oct'], array_column($chart->dates, 'label'));
        $this->assertSame(['3.2%', '29.0%', '51.6%', '77.4%', '100.0%'], array_column($chart->dates, 'left'));
        $this->assertSame([false, true, false, true, false], array_column($chart->dates, 'isMinor'));
    }

    public function test_there_is_no_chart_without_settled_bets(): void
    {
        $this->assertNull(ProfitChart::fromSeries(new ProfitSeries(Period::Week, []), Currency::Eur));
    }

    /**
     * @param  list<int>  $cumulativeProfits  one per period, in minor units
     */
    private function series(Period $period, string $start, array $cumulativeProfits): ProfitSeries
    {
        $points = [];
        $date = CarbonImmutable::parse($start);
        $previous = 0;

        foreach ($cumulativeProfits as $cumulative) {
            $points[] = new ProfitPoint($date, $cumulative - $previous, $cumulative);
            $date = $period->next($date);
            $previous = $cumulative;
        }

        return new ProfitSeries($period, $points);
    }
}
