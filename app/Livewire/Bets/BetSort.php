<?php

namespace App\Livewire\Bets;

use App\Models\Bet;
use App\Models\Selection;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders of the bets list. Ties go to the most recently placed bet.
 */
enum BetSort: string
{
    /** By the start of the next pending selection; bets without one last. */
    case StartsSoonest = 'starts';

    /** By placement date, newest first. */
    case Newest = 'newest';

    /** By placement date, oldest first. */
    case Oldest = 'oldest';

    /** By settlement date, latest first. */
    case RecentlySettled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::StartsSoonest => 'Starts soonest',
            self::Newest => 'Newest first',
            self::Oldest => 'Oldest first',
            self::RecentlySettled => 'Recently settled',
        };
    }

    /**
     * @param  Builder<Bet>  $query
     * @return Builder<Bet>
     */
    public function apply(Builder $query): Builder
    {
        match ($this) {
            self::StartsSoonest => $query
                ->orderBy(self::nextPendingStart('case when min(event_starts_at) is null then 1 else 0 end'))
                ->orderBy(self::nextPendingStart('min(event_starts_at)')),
            self::Newest => $query,
            self::Oldest => $query->oldest('placed_at')->oldest('id'),
            self::RecentlySettled => $query->orderByDesc('settled_at'),
        };

        return $query->latest('placed_at')->latest('id');
    }

    /**
     * A subquery over the bet's pending selections, for ordering. Bets are ordered by the
     * subquery itself rather than by a selected alias, which Postgres doesn't allow inside
     * an expression, and "no start time" is its own key because databases disagree on
     * where nulls sort.
     *
     * @return Builder<Selection>
     */
    private static function nextPendingStart(string $aggregate): Builder
    {
        return Selection::query()
            ->selectRaw($aggregate)
            ->whereColumn('selections.bet_id', 'bets.id')
            ->whereNull('result');
    }
}
