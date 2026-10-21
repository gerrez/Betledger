<?php

namespace App\Livewire\Statistics;

use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Percent;
use App\Domain\Statistics\Totals;

/**
 * Statistics totals as the statistics page shows them: amounts without the currency
 * symbol (the page names the base currency once), ratios as percentages, and "—" for a
 * ratio with nothing to divide by.
 */
final readonly class TotalsDisplay
{
    /** Shown for a ratio or average there is nothing to compute from. */
    public const NONE = '—';

    /** The ROI, either way, at which an odds-range bar is full width: 25 %. */
    public const FULL_BAR_ROI = '0.25';

    /**
     * @param  'profit'|'loss'|'even'  $profitTone
     * @param  'profit'|'loss'|'even'  $roiTone
     * @param  string  $roiBar  the width of the ROI bar, a CSS percentage ("27.2%")
     * @param  'profit'|'loss'|'even'  $clvTone
     * @param  string  $beatCloseBar  the width of the beat-the-close bar, a CSS percentage
     */
    private function __construct(
        public int $settledBets,
        public int $openBets,
        public string $profit,
        public string $profitTone,
        public string $turnover,
        public string $roi,
        public string $roiTone,
        public string $roiBar,
        public string $strikeRate,
        public string $averageOdds,
        public string $averageClv,
        public string $clvTone,
        public int $clvBets,
        public int $beatCloseBets,
        public string $beatCloseRate,
        public string $beatCloseBar,
        public string $openStake,
    ) {}

    public static function fromTotals(Totals $totals, Currency $currency): self
    {
        $roi = $totals->roi === null ? self::NONE : Percent::format($totals->roi, signed: true);
        $averageClv = $totals->averageClv === null ? self::NONE : Percent::format($totals->averageClv, signed: true);

        return new self(
            settledBets: $totals->settledBets,
            openBets: $totals->openBets,
            profit: Money::formatWithoutSymbol($totals->profit, signed: true),
            profitTone: match (true) {
                $totals->profit > 0 => 'profit',
                $totals->profit < 0 => 'loss',
                default => 'even',
            },
            turnover: Money::formatWithoutSymbol($totals->turnover),
            roi: $roi,
            roiTone: self::tone($roi),
            roiBar: self::roiBar($totals->roi),
            strikeRate: $totals->strikeRate === null ? self::NONE : Percent::format($totals->strikeRate),
            averageOdds: $totals->averageOdds === null ? self::NONE : Decimal::round($totals->averageOdds, 2),
            averageClv: $averageClv,
            clvTone: self::tone($averageClv),
            clvBets: $totals->clvBets,
            beatCloseBets: $totals->beatCloseBets,
            beatCloseRate: $totals->beatCloseRate === null ? self::NONE : Percent::format($totals->beatCloseRate, places: 0),
            beatCloseBar: $totals->beatCloseRate === null ? '0%' : Percent::format($totals->beatCloseRate),
            openStake: Money::format($totals->openStake, $currency),
        );
    }

    /**
     * The tone of a percentage as shown, so one that rounds to 0.0% is even.
     *
     * @return 'profit'|'loss'|'even'
     */
    private static function tone(string $percent): string
    {
        return match (true) {
            str_starts_with($percent, '+') => 'profit',
            str_starts_with($percent, Money::MINUS) => 'loss',
            default => 'even',
        };
    }

    /**
     * The width of a ROI bar: in proportion to the ROI, full at FULL_BAR_ROI or more.
     */
    private static function roiBar(?string $roi): string
    {
        if ($roi === null) {
            return '0%';
        }

        $share = Decimal::divide(ltrim($roi, '-'), self::FULL_BAR_ROI);

        return Decimal::compare($share, '1') >= 0 ? '100%' : Percent::format($share);
    }
}
