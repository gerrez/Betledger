<?php

namespace App\Livewire\Bets;

use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Selection;
use Carbon\CarbonInterface;

/**
 * What a bet's card in the bets list shows, as display strings. Expects the bet's
 * bookmaker and its selections' competitions to be loaded.
 */
final readonly class BetCard
{
    /**
     * @param  list<array{pick: string, event: string, odds: string, status: string, tone: 'won'|'lost'|'void'|'pending'}>  $legs
     * @param  array{label: string, payout: string, profit: string, tone: 'profit'|'loss'|'even'}|null  $result  null while the bet is open
     */
    private function __construct(
        public int $id,
        public string $title,
        public string $meta,
        public string $stake,
        public string $totalOdds,
        public bool $isFreeBet,
        public bool $isAccumulator,
        public array $legs,
        public ?array $result,
    ) {}

    public static function fromBet(Bet $bet, CarbonInterface $now): self
    {
        $selections = $bet->selections;
        $first = $selections->firstOrFail();
        $isAccumulator = $selections->count() > 1;

        $meta = $isAccumulator
            ? [$bet->bookmaker->name, 'placed '.self::formatTime($bet->placed_at, $now)]
            : [
                $first->competition?->name,
                $bet->bookmaker->name,
                $first->event_starts_at === null
                    ? 'placed '.self::formatTime($bet->placed_at, $now)
                    : self::formatTime($first->event_starts_at, $now),
            ];

        return new self(
            id: $bet->id,
            title: $isAccumulator ? $selections->count().'-fold accumulator' : $first->event_name,
            meta: implode(' · ', array_filter($meta)),
            stake: Money::format($bet->stake, $bet->currency),
            totalOdds: Odds::format($bet->total_odds),
            isFreeBet: $bet->is_free_bet,
            isAccumulator: $isAccumulator,
            legs: array_values($selections->map(fn (Selection $selection): array => self::leg($selection, $now))->all()),
            result: self::result($bet),
        );
    }

    /**
     * A date and time relative to now: "today 18:30", "tomorrow 18:30", "yesterday 18:30",
     * the weekday within six days ("Sat 11:05"), else the date ("14 Oct 18:30", or
     * "14 Oct 2025" in another year).
     */
    public static function formatTime(CarbonInterface $time, CarbonInterface $now): string
    {
        $days = (int) $now->copy()->startOfDay()->diffInDays($time->copy()->startOfDay());

        return match (true) {
            $days === 0 => $time->format('\t\o\d\a\y H:i'),
            $days === 1 => $time->format('\t\o\m\o\r\r\o\w H:i'),
            $days === -1 => $time->format('\y\e\s\t\e\r\d\a\y H:i'),
            abs($days) < 7 => $time->format('D H:i'),
            $time->year === $now->year => $time->format('j M H:i'),
            default => $time->format('j M Y'),
        };
    }

    /**
     * @return array{pick: string, event: string, odds: string, status: string, tone: 'won'|'lost'|'void'|'pending'}
     */
    private static function leg(Selection $selection, CarbonInterface $now): array
    {
        $event = $selection->event_name;

        if ($selection->result === null && $selection->event_starts_at !== null) {
            $event .= ' · '.self::formatTime($selection->event_starts_at, $now);
        }

        return [
            'pick' => $selection->pick,
            'event' => $event,
            'odds' => Odds::format($selection->odds),
            'status' => $selection->result?->label() ?? 'Pending',
            'tone' => match ($selection->result) {
                SelectionResult::Won, SelectionResult::HalfWon => 'won',
                SelectionResult::Lost, SelectionResult::HalfLost => 'lost',
                SelectionResult::Void => 'void',
                null => 'pending',
            },
        ];
    }

    /**
     * @return array{label: string, payout: string, profit: string, tone: 'profit'|'loss'|'even'}|null
     */
    private static function result(Bet $bet): ?array
    {
        if (! $bet->isSettled() || $bet->payout === null || $bet->profit === null) {
            return null;
        }

        $singleResult = $bet->selections->count() === 1 ? $bet->selections->firstOrFail()->result : null;

        return [
            'label' => $singleResult === null ? 'Settled' : 'Settled: '.$singleResult->label(),
            'payout' => Money::format($bet->payout, $bet->currency),
            'profit' => Money::format($bet->profit, $bet->currency, signed: true),
            'tone' => match (true) {
                $bet->profit > 0 => 'profit',
                $bet->profit < 0 => 'loss',
                default => 'even',
            },
        ];
    }
}
