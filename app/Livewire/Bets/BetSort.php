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
                ->addSelect(['next_event_starts_at' => Selection::query()
                    ->selectRaw('min(event_starts_at)')
                    ->whereColumn('selections.bet_id', 'bets.id')
                    ->whereNull('result'),
                ])
                ->orderByRaw('next_event_starts_at is null')
                ->orderBy('next_event_starts_at'),
            self::Newest => $query,
            self::Oldest => $query->oldest('placed_at')->oldest('id'),
            self::RecentlySettled => $query->orderByDesc('settled_at'),
        };

        return $query->latest('placed_at')->latest('id');
    }
}
