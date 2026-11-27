<?php

namespace App\Livewire\Statistics;

use App\Domain\Money;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\Drawdown;
use App\Domain\Statistics\RiskFigures;
use App\Domain\Statistics\Streak;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Risk figures as the statistics page's Risk panel shows them: one tile per figure, with
 * amounts without the currency symbol (like the rest of the page) and a fall shown as a
 * negative amount. The biggest win and loss carry their bet's id so the tile can link to it.
 */
final class RiskDisplay
{
    /**
     * @return list<array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string, betId: int|null}>
     */
    public static function tiles(RiskFigures $risk): array
    {
        return [
            self::drawdownTile('Max drawdown', $risk->maxDrawdown, fn (Drawdown $drawdown): string => self::period($drawdown->peakAt, $drawdown->lowAt), 'Never below an earlier peak'),
            self::drawdownTile('Current drawdown', $risk->currentDrawdown, fn (Drawdown $drawdown): string => $drawdown->peakAt === null ? 'Below the start' : 'Since the peak on '.$drawdown->peakAt->format('j M Y'), 'At the peak'),
            self::tile('Longest winning streak', (string) $risk->longestWinningStreak, 'even', 'wins in a row'),
            self::tile('Longest losing streak', (string) $risk->longestLosingStreak, 'even', 'losses in a row'),
            self::tile('Current streak', self::streak($risk->currentStreak), 'even', 'pushes skipped'),
            self::betTile('Biggest win', $risk->biggestWin, 'No winning bets'),
            self::betTile('Biggest loss', $risk->biggestLoss, 'No losing bets'),
            self::tile('Average stake', $risk->averageStake === null ? TotalsDisplay::NONE : Money::formatWithoutSymbol($risk->averageStake), 'even', 'settled cash bets'),
        ];
    }

    /**
     * @param  callable(Drawdown): string  $describe
     * @return array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string, betId: int|null}
     */
    private static function drawdownTile(string $label, ?Drawdown $drawdown, callable $describe, string $none): array
    {
        return $drawdown === null
            ? self::tile($label, Money::formatWithoutSymbol(0), 'even', $none)
            : self::tile($label, Money::formatWithoutSymbol(-$drawdown->amount, signed: true), 'loss', $describe($drawdown));
    }

    /**
     * @return array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string, betId: int|null}
     */
    private static function betTile(string $label, ?BetFigures $bet, string $none): array
    {
        if ($bet === null || $bet->profit === null) {
            return self::tile($label, TotalsDisplay::NONE, 'even', $none);
        }

        return self::tile(
            $label,
            Money::formatWithoutSymbol($bet->profit, signed: true),
            $bet->profit > 0 ? 'profit' : 'loss',
            $bet->eventAt?->format('j M Y') ?? '',
            $bet->betId,
        );
    }

    /**
     * @param  'profit'|'loss'|'even'  $tone
     * @return array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string, betId: int|null}
     */
    private static function tile(string $label, string $value, string $tone, string $sub, ?int $betId = null): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone, 'sub' => $sub, 'betId' => $betId];
    }

    private static function streak(?Streak $streak): string
    {
        if ($streak === null) {
            return TotalsDisplay::NONE;
        }

        return $streak->length.' '.Str::plural($streak->isWinning ? 'win' : 'loss', $streak->length);
    }

    /**
     * "1 Oct – 8 Oct 2026", the year once when both are in the same one; "Start – 8 Oct
     * 2026" when the peak is the start.
     */
    private static function period(?CarbonImmutable $from, CarbonImmutable $to): string
    {
        $start = match (true) {
            $from === null => 'Start',
            $from->year === $to->year => $from->format('j M'),
            default => $from->format('j M Y'),
        };

        return $start.' – '.$to->format('j M Y');
    }
}
