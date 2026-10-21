<?php

namespace App\Livewire\Bets;

use App\Domain\ClosingLineValue;
use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Tag;
use Carbon\CarbonInterface;

/**
 * What the bet detail page shows, as display strings. Expects the bet's bookmaker,
 * tipster, tags and its selections' sports, competitions and markets to be loaded.
 */
final readonly class BetDetail
{
    /**
     * @param  array{label: string, profit: string, profitInBase: string|null, payout: string, isManual: bool, calculatedPayout: string, tone: 'profit'|'loss'|'even'}|null  $result  null while the bet is open
     * @param  array{payout: string, profit: string}|null  $potential  what an open bet pays if every pending selection wins
     * @param  list<array{id: int, pick: string, meta: string, startsAt: string|null, odds: string, hasResult: bool, buttons: list<array{result: string, label: string, isPressed: bool, tone: 'won'|'lost'|'void'}>, closingOdds: string|null, clv: array{label: string, tone: 'profit'|'loss'|'even'}|null}>  $selections
     * @param  array{percent: string, tone: 'profit'|'loss'|'even'}|null  $clv  the bet's CLV, when every selection has closing odds
     * @param  list<array{label: string, value: string}>  $facts
     * @param  string|null  $confirmResultChange  the warning to confirm before a result changes, while the payout was entered by hand
     */
    private function __construct(
        public string $title,
        public bool $isAccumulator,
        public bool $isFreeBet,
        public ?array $result,
        public ?array $potential,
        public array $selections,
        public ?array $clv,
        public array $facts,
        public ?string $notes,
        public string $currency,
        public ?string $confirmResultChange,
    ) {}

    public static function fromBet(Bet $bet, Currency $baseCurrency, SettlementCalculator $calculator): self
    {
        $selections = $bet->selections;
        $isAccumulator = $selections->count() > 1;
        $legs = array_values($selections->map(fn (Selection $selection): Leg => new Leg($selection->odds, $selection->result))->all());
        $clv = ClosingLineValue::forBet($bet->total_odds, array_values($selections->map(fn (Selection $selection): ?string => $selection->closing_odds)->all()));

        return new self(
            title: $isAccumulator ? $selections->count().'-fold accumulator' : 'Single bet',
            isAccumulator: $isAccumulator,
            isFreeBet: $bet->is_free_bet,
            result: self::result($bet, $baseCurrency, $calculator, $legs),
            potential: self::potential($bet, $calculator, $legs),
            selections: array_values($selections->map(fn (Selection $selection): array => self::selection($selection))->all()),
            clv: $isAccumulator && $clv !== null ? ['percent' => ClosingLineValue::formatPercent($clv), 'tone' => self::clvTone($clv)] : null,
            facts: self::facts($bet, $baseCurrency),
            notes: $bet->notes,
            currency: $bet->currency->value,
            confirmResultChange: $bet->payout_is_manual && $bet->payout !== null
                ? 'You entered this payout ('.Money::format($bet->payout, $bet->currency).') yourself. Changing a result replaces it with the calculated payout.'
                : null,
        );
    }

    /**
     * A date and time in full: "7 Oct 2026, 17:42".
     */
    public static function formatDateTime(CarbonInterface $time): string
    {
        return $time->format('j M Y, H:i');
    }

    /**
     * @param  list<Leg>  $legs
     * @return array{label: string, profit: string, profitInBase: string|null, payout: string, isManual: bool, calculatedPayout: string, tone: 'profit'|'loss'|'even'}|null
     */
    private static function result(Bet $bet, Currency $baseCurrency, SettlementCalculator $calculator, array $legs): ?array
    {
        if (! $bet->isSettled() || $bet->payout === null || $bet->profit === null) {
            return null;
        }

        $single = $bet->selections->count() === 1 ? $bet->selections->firstOrFail()->result : null;
        $calculated = $calculator->settle($bet->stake, $bet->is_free_bet, $legs)->payout ?? $bet->payout;

        return [
            'label' => $single?->label() ?? 'Settled',
            'profit' => Money::format($bet->profit, $bet->currency, signed: true),
            'profitInBase' => $bet->currency === $baseCurrency ? null : '≈ '.Money::format(Money::convert($bet->profit, $bet->exchange_rate), $baseCurrency, signed: true),
            'payout' => Money::format($bet->payout, $bet->currency),
            'isManual' => $bet->payout_is_manual,
            'calculatedPayout' => Money::format($calculated, $bet->currency),
            'tone' => match (true) {
                $bet->profit > 0 => 'profit',
                $bet->profit < 0 => 'loss',
                default => 'even',
            },
        ];
    }

    /**
     * @param  list<Leg>  $legs
     * @return array{payout: string, profit: string}|null
     */
    private static function potential(Bet $bet, SettlementCalculator $calculator, array $legs): ?array
    {
        if ($bet->isSettled()) {
            return null;
        }

        $settlement = $calculator->ifPendingWin($bet->stake, $bet->is_free_bet, $legs);

        return [
            'payout' => Money::format($settlement->payout, $bet->currency),
            'profit' => Money::format($settlement->profit, $bet->currency, signed: true),
        ];
    }

    /**
     * @return array{id: int, pick: string, meta: string, startsAt: string|null, odds: string, hasResult: bool, buttons: list<array{result: string, label: string, isPressed: bool, tone: 'won'|'lost'|'void'}>, closingOdds: string|null, clv: array{label: string, tone: 'profit'|'loss'|'even'}|null}
     */
    private static function selection(Selection $selection): array
    {
        $clv = $selection->closing_odds === null ? null : ClosingLineValue::forBet($selection->odds, [$selection->closing_odds]);

        return [
            'id' => $selection->id,
            'pick' => $selection->pick,
            'meta' => implode(' · ', array_filter([$selection->event_name, $selection->competition?->name, $selection->market?->name])),
            'startsAt' => $selection->event_starts_at === null ? null : self::formatDateTime($selection->event_starts_at),
            'odds' => Odds::format($selection->odds),
            'hasResult' => $selection->result !== null,
            'buttons' => array_map(fn (SelectionResult $result): array => [
                'result' => $result->value,
                'label' => $result->label(),
                'isPressed' => $selection->result === $result,
                'tone' => match ($result) {
                    SelectionResult::Won, SelectionResult::HalfWon => 'won',
                    SelectionResult::Lost, SelectionResult::HalfLost => 'lost',
                    SelectionResult::Void => 'void',
                },
            ], SelectionResult::cases()),
            'closingOdds' => $selection->closing_odds === null ? null : Odds::format($selection->closing_odds),
            'clv' => $clv === null ? null : [
                'label' => match (self::clvTone($clv)) {
                    'profit' => 'Beat the close',
                    'loss' => 'Missed the close',
                    'even' => 'Matched the close',
                }.' '.ClosingLineValue::formatPercent($clv),
                'tone' => self::clvTone($clv),
            ],
        ];
    }

    /**
     * The tone of a CLV as shown, so one that rounds to 0.0% is even.
     *
     * @return 'profit'|'loss'|'even'
     */
    private static function clvTone(string $clv): string
    {
        $percent = ClosingLineValue::formatPercent($clv);

        return match (true) {
            str_starts_with($percent, '+') => 'profit',
            $percent === '0.0%' => 'even',
            default => 'loss',
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function facts(Bet $bet, Currency $baseCurrency): array
    {
        $inBaseCurrency = $bet->currency !== $baseCurrency;
        $rate = $inBaseCurrency ? ', rate '.Decimal::normalize($bet->exchange_rate) : '';
        $stake = Money::format($bet->stake, $bet->currency)
            .($inBaseCurrency ? ' ≈ '.Money::format(Money::convert($bet->stake, $bet->exchange_rate), $baseCurrency) : '')
            .($bet->is_free_bet ? ' · free bet' : '');
        $sports = $bet->selections->map(fn (Selection $selection): string => $selection->sport->name)->unique()->implode(', ');

        $facts = [
            ['label' => 'Bookmaker', 'value' => "{$bet->bookmaker->name} ({$bet->currency->value}{$rate})"],
            ['label' => 'Stake', 'value' => $stake],
            $bet->selections->count() > 1 ? ['label' => 'Total odds', 'value' => Odds::format($bet->total_odds)] : null,
            ['label' => 'Placed', 'value' => self::formatDateTime($bet->placed_at)],
            $bet->settled_at === null ? null : ['label' => 'Settled', 'value' => self::formatDateTime($bet->settled_at)],
            ['label' => 'Sport', 'value' => $sports],
            $bet->tipster === null ? null : ['label' => 'Tipster', 'value' => $bet->tipster->name],
            $bet->tags->isEmpty() ? null : ['label' => 'Tags', 'value' => $bet->tags->map(fn (Tag $tag): string => $tag->name)->sort()->implode(' · ')],
        ];

        return array_values(array_filter($facts));
    }
}
