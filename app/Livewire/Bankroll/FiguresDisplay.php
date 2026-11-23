<?php

namespace App\Livewire\Bankroll;

use App\Domain\Bankroll\BankrollFigures;
use App\Domain\Money;
use App\Domain\Percent;
use App\Livewire\Statistics\TotalsDisplay;

/**
 * Bankroll figures as the bankroll page shows them: amounts with their currency symbol
 * (rows can be in different currencies), profit and ROI with a sign, and "—" for a ROI
 * without turnover.
 */
final readonly class FiguresDisplay
{
    /**
     * @param  'profit'|'loss'|'even'  $profitTone
     * @param  'profit'|'loss'|'even'  $roiTone
     * @param  'loss'|'even'  $balanceTone
     */
    private function __construct(
        public string $bets,
        public string $openStake,
        public string $openBets,
        public string $turnover,
        public string $profit,
        public string $profitTone,
        public string $roi,
        public string $roiTone,
        public string $balance,
        public string $balanceTone,
    ) {}

    public static function fromFigures(BankrollFigures $figures): self
    {
        $roi = $figures->roi === null ? TotalsDisplay::NONE : Percent::format($figures->roi, signed: true);

        return new self(
            bets: $figures->openBets > 0 ? "{$figures->bets} · {$figures->openBets} open" : (string) $figures->bets,
            openStake: Money::format($figures->openStake, $figures->currency),
            openBets: $figures->openBets === 1 ? '1 open bet' : "{$figures->openBets} open bets",
            turnover: Money::format($figures->turnover, $figures->currency),
            profit: Money::format($figures->profit, $figures->currency, signed: true),
            profitTone: self::tone($figures->profit),
            roi: $roi,
            roiTone: match (true) {
                str_starts_with($roi, '+') => 'profit',
                str_starts_with($roi, Money::MINUS) => 'loss',
                default => 'even',
            },
            balance: Money::format($figures->balance, $figures->currency),
            balanceTone: $figures->balance < 0 ? 'loss' : 'even',
        );
    }

    /**
     * @return 'profit'|'loss'|'even'
     */
    private static function tone(int $amount): string
    {
        return match (true) {
            $amount > 0 => 'profit',
            $amount < 0 => 'loss',
            default => 'even',
        };
    }
}
