<?php

namespace App\Livewire\Bets;

use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Selection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a bet's card in the bets list shows, as display strings. Expects the bet's
 * bookmaker and its selections' competitions to be loaded.
 */
final readonly class BetCard
{
    /**
     * @param  list<array{pick: string, event: string, odds: string, status: string, tone: 'won'|'lost'|'void'|'pending'}>  $legs
     * @param  array{label: string, payout: string, profit: string, tone: 'profit'|'loss'|'even'}|null  $result  null while the bet is open
     * @param  array{selection: int, prompt: string}|null  $settle  the pending selection an open bet offers result buttons for
     * @param  string|null  $lastResult  the undoable result of a selection that left the bet open
     * @param  bool  $canUndo  whether the card offers to undo the latest result set from the bets list
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
        public ?array $settle,
        public ?string $lastResult,
        public bool $canUndo,
    ) {}

    /**
     * @param  Selection|null  $undoable  the bet's selection whose result the card offers to undo
     */
    public static function fromBet(Bet $bet, CarbonInterface $now, ?Selection $undoable = null): self
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
            result: self::result($bet, $undoable),
            settle: self::settle($bet),
            lastResult: $undoable !== null && ! $bet->isSettled() ? $undoable->pick.': '.$undoable->result?->label() : null,
            canUndo: $undoable !== null,
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
    private static function result(Bet $bet, ?Selection $undoable): ?array
    {
        if (! $bet->isSettled() || $bet->payout === null || $bet->profit === null) {
            return null;
        }

        return [
            'label' => self::settledLabel($bet->selections, $undoable?->result),
            'payout' => Money::format($bet->payout, $bet->currency),
            'profit' => Money::format($bet->profit, $bet->currency, signed: true),
            'tone' => match (true) {
                $bet->profit > 0 => 'profit',
                $bet->profit < 0 => 'loss',
                default => 'even',
            },
        ];
    }

    /**
     * "Settled: Won" for a single; for an accumulator "Settled", or which result just
     * settled it when that can be undone.
     *
     * @param  Collection<int, Selection>  $selections
     */
    private static function settledLabel(Collection $selections, ?SelectionResult $undoable): string
    {
        if ($selections->count() === 1) {
            $result = $selections->firstOrFail()->result;

            return $result === null ? 'Settled' : 'Settled: '.$result->label();
        }

        if ($undoable === null) {
            return 'Settled';
        }

        $leg = $selections->contains(fn (Selection $selection): bool => $selection->result === null) ? 'Leg' : 'Final leg';

        return $leg.' '.mb_strtolower($undoable->label()).' · bet settled';
    }

    /**
     * The selection an open bet offers result buttons for: the pending one that starts
     * soonest (those without a start time last, then in entry order).
     *
     * @return array{selection: int, prompt: string}|null
     */
    private static function settle(Bet $bet): ?array
    {
        if ($bet->isSettled()) {
            return null;
        }

        $pending = $bet->selections
            ->filter(fn (Selection $selection): bool => $selection->result === null)
            ->sortBy([
                fn (Selection $a, Selection $b): int => ($a->event_starts_at === null) <=> ($b->event_starts_at === null),
                fn (Selection $a, Selection $b): int => $a->event_starts_at <=> $b->event_starts_at,
                fn (Selection $a, Selection $b): int => $a->position <=> $b->position,
            ]);
        $next = $pending->first();

        if ($next === null) {
            return null;
        }

        return [
            'selection' => $next->id,
            'prompt' => match (true) {
                $bet->selections->count() === 1 => 'How did it go?',
                $pending->count() === 1 => "Settle the last selection, {$next->pick}:",
                default => "Settle the next selection, {$next->pick}:",
            },
        ];
    }
}
