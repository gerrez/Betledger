<?php

namespace Tests\Unit\Livewire;

use App\Domain\Currency;
use App\Domain\Statistics\Totals;
use App\Livewire\Statistics\TotalsDisplay;
use PHPUnit\Framework\TestCase;

class TotalsDisplayTest extends TestCase
{
    public function test_shows_amounts_without_symbol_and_ratios_as_percentages(): void
    {
        $display = TotalsDisplay::fromTotals($this->totals(
            profit: 348620,
            turnover: 6124000,
            roi: '0.0569268452',
            strikeRate: '0.5238095238',
            averageOdds: '2.1050000000',
            averageClv: '-0.0245',
            beatCloseRate: '0.6338028169',
            openStake: 154200,
        ), Currency::Dkk);

        $this->assertSame('+3,486.20', $display->profit);
        $this->assertSame('profit', $display->profitTone);
        $this->assertSame('61,240.00', $display->turnover);
        $this->assertSame('+5.7%', $display->roi);
        $this->assertSame('profit', $display->roiTone);
        $this->assertSame('52.4%', $display->strikeRate);
        $this->assertSame('2.11', $display->averageOdds);
        $this->assertSame('−2.5%', $display->averageClv);
        $this->assertSame('loss', $display->clvTone);
        $this->assertSame('63%', $display->beatCloseRate);
        $this->assertSame('63.4%', $display->beatCloseBar);
        $this->assertSame('1,542.00 kr', $display->openStake);
    }

    public function test_ratios_with_nothing_to_divide_by_show_a_dash(): void
    {
        $display = TotalsDisplay::fromTotals($this->totals(), Currency::Eur);

        $this->assertSame('0.00', $display->profit);
        $this->assertSame('even', $display->profitTone);
        $this->assertSame(['—', '—', '—', '—', '—'], [$display->roi, $display->strikeRate, $display->averageOdds, $display->averageClv, $display->beatCloseRate]);
        $this->assertSame(['even', 'even'], [$display->roiTone, $display->clvTone]);
        $this->assertSame(['0%', '0%'], [$display->roiBar, $display->beatCloseBar]);
    }

    public function test_a_roi_that_rounds_to_zero_is_even(): void
    {
        $display = TotalsDisplay::fromTotals($this->totals(profit: -1, roi: '-0.0001'), Currency::Eur);

        $this->assertSame('0.0%', $display->roi);
        $this->assertSame('even', $display->roiTone);
        $this->assertSame('loss', $display->profitTone);
    }

    public function test_roi_bars_are_in_proportion_and_full_from_25_percent(): void
    {
        $this->assertSame('27.2%', TotalsDisplay::fromTotals($this->totals(roi: '0.068'), Currency::Eur)->roiBar);
        $this->assertSame('88.0%', TotalsDisplay::fromTotals($this->totals(roi: '-0.22'), Currency::Eur)->roiBar);
        $this->assertSame('100%', TotalsDisplay::fromTotals($this->totals(roi: '0.25'), Currency::Eur)->roiBar);
        $this->assertSame('100%', TotalsDisplay::fromTotals($this->totals(roi: '-1'), Currency::Eur)->roiBar);
    }

    private function totals(
        int $profit = 0,
        int $turnover = 0,
        ?string $roi = null,
        ?string $strikeRate = null,
        ?string $averageOdds = null,
        ?string $averageClv = null,
        ?string $beatCloseRate = null,
        int $openStake = 0,
    ): Totals {
        return new Totals(
            settledBets: 0,
            openBets: 0,
            turnover: $turnover,
            profit: $profit,
            roi: $roi,
            strikeRate: $strikeRate,
            averageOdds: $averageOdds,
            averageClv: $averageClv,
            clvBets: 0,
            beatCloseBets: 0,
            beatCloseRate: $beatCloseRate,
            openStake: $openStake,
        );
    }
}
