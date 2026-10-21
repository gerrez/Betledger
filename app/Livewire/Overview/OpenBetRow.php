<?php

namespace App\Livewire\Overview;

use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\BetCard;
use App\Models\Bet;
use App\Models\Selection;
use Carbon\CarbonInterface;

/**
 * An open bet as the overview's preview shows it, as display strings. Expects the bet's
 * bookmaker and selections to be loaded.
 */
final readonly class OpenBetRow
{
    private function __construct(
        public int $id,
        public string $title,
        public string $meta,
        public string $stake,
        public string $totalOdds,
    ) {}

    /**
     * A single shows its pick, bookmaker and start ("Arsenal −0.25 · Pinnacle · today
     * 18:30"); an accumulator how far it has got and its bookmaker ("2 of 3 won ·
     * Bet365"). A free bet says so last.
     */
    public static function fromBet(Bet $bet, CarbonInterface $now): self
    {
        $selections = $bet->selections;
        $first = $selections->firstOrFail();
        $isAccumulator = $selections->count() > 1;

        $meta = $isAccumulator
            ? [self::progress(array_values($selections->all())), $bet->bookmaker->name]
            : [$first->pick, $bet->bookmaker->name, $first->event_starts_at === null ? null : BetCard::formatTime($first->event_starts_at, $now)];

        return new self(
            id: $bet->id,
            title: $isAccumulator ? $selections->count().'-fold accumulator' : $first->event_name,
            meta: implode(' · ', array_filter([...$meta, $bet->is_free_bet ? 'free bet' : null])),
            stake: Money::format($bet->stake, $bet->currency),
            totalOdds: Odds::format($bet->total_odds),
        );
    }

    /**
     * How many of an accumulator's selections have a result: "2 of 3 won" while every
     * one of them won, else "2 of 3 settled"; null while none has.
     *
     * @param  list<Selection>  $selections
     */
    private static function progress(array $selections): ?string
    {
        $results = array_values(array_filter(array_map(fn (Selection $selection): ?SelectionResult => $selection->result, $selections)));

        if ($results === []) {
            return null;
        }

        $allWon = array_filter($results, fn (SelectionResult $result): bool => $result !== SelectionResult::Won) === [];

        return count($results).' of '.count($selections).($allWon ? ' won' : ' settled');
    }
}
