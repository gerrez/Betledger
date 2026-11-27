<?php

namespace App\Domain\Bets;

use App\Domain\BetStatus;
use App\Domain\Statistics\StatisticsFilter;
use App\Models\Bet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

/**
 * The conditions that pick a user's bets: the filters statistics and the bets list share
 * (a StatisticsFilter) and the bets list's text search. Both start from the user's own
 * bets, so an id or a term can only ever match that user's bets.
 */
final class BetFilterQuery
{
    /**
     * The columns the text search looks in besides the bet's notes, by the relation
     * (from the bet) they are on.
     */
    private const SEARCHED_COLUMNS = [
        'selections' => ['selections.event_name', 'selections.pick'],
        'selections.competition' => ['competitions.name'],
        'selections.teams' => ['teams.name'],
    ];

    /**
     * The user's bets that pass the filter. A date range takes settled bets by their
     * event date (`event_at`) and open bets by their placed date, each day being that day
     * in the user's time zone. Selection-level filters match a bet when any of its
     * selections matches, so "no competition" is a bet with a selection without one, as
     * in the statistics breakdown. An odds band takes the bets whose total odds are above
     * its lower bound and up to and including its upper bound.
     *
     * @return Builder<Bet>
     */
    public function filtered(User $user, StatisticsFilter $filter): Builder
    {
        $hasSelection = fn (string $column, ?int $id) => fn (Builder $query) => $query->whereHas('selections', fn (Builder $selections) => $id === StatisticsFilter::NONE
            ? $selections->whereNull($column)
            : $selections->where($column, $id));
        $localTime = $user->localTime();
        $from = $filter->from === null ? null : $localTime->dayStart($filter->from);
        $until = $filter->to === null ? null : $localTime->dayStart($filter->to->addDay());

        return $user->bets()->getQuery()
            ->when($filter->status !== null, fn (Builder $query) => $query->where('status', $filter->status))
            ->when($filter->from !== null || $filter->to !== null, fn (Builder $query) => $query->where(fn (Builder $dates) => $dates
                ->where(fn (Builder $settled) => $this->between($settled->where('status', BetStatus::Settled), 'event_at', $from, $until))
                ->orWhere(fn (Builder $open) => $this->between($open->where('status', BetStatus::Open), 'placed_at', $from, $until))))
            ->when($filter->bookmakerId !== null, fn (Builder $query) => $query->where('bookmaker_id', $filter->bookmakerId))
            ->when($filter->tipsterId === StatisticsFilter::NONE, fn (Builder $query) => $query->whereNull('tipster_id'))
            ->when($filter->tipsterId !== null && $filter->tipsterId !== StatisticsFilter::NONE, fn (Builder $query) => $query->where('tipster_id', $filter->tipsterId))
            ->when($filter->type !== null, fn (Builder $query) => $query->where('type', $filter->type))
            ->when(! $filter->includeFreeBets, fn (Builder $query) => $query->where('is_free_bet', false))
            ->when($filter->tagId === StatisticsFilter::NONE, fn (Builder $query) => $query->whereDoesntHave('tags'))
            ->when($filter->tagId !== null && $filter->tagId !== StatisticsFilter::NONE, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($filter->tagId)))
            ->when($filter->sportId !== null, $hasSelection('sport_id', $filter->sportId))
            ->when($filter->competitionId !== null, $hasSelection('competition_id', $filter->competitionId))
            ->when($filter->marketId !== null, $hasSelection('market_id', $filter->marketId))
            ->when($filter->teamId === StatisticsFilter::NONE, fn (Builder $query) => $query->whereHas('selections', fn (Builder $selections) => $selections->whereDoesntHave('teams')))
            ->when($filter->teamId !== null && $filter->teamId !== StatisticsFilter::NONE, fn (Builder $query) => $query->whereHas('selections.teams', fn (Builder $teams) => $teams->whereKey($filter->teamId)))
            ->when($filter->oddsBand?->above() !== null, fn (Builder $query) => $query->where('total_odds', '>', $filter->oddsBand?->above()))
            ->when($filter->oddsBand?->upTo() !== null, fn (Builder $query) => $query->where('total_odds', '<=', $filter->oddsBand?->upTo()));
    }

    /**
     * Limit the bets to those whose notes, or any selection's event name, pick, team or
     * competition, contain the term, ignoring case. `%`, `_`, `!` and `\` in the term are
     * matched literally. A blank term changes nothing.
     *
     * The comparison is `LOWER(column) LIKE ?` with a lower-cased term on every
     * database, so SQLite and Postgres agree (no `ILIKE`); SQLite's `LOWER` only folds
     * ASCII letters. The escape character is `!`, not a backslash: PDO's Postgres
     * driver reads `'\'` as an escaped quote and loses the placeholders after it.
     *
     * @param  Builder<Bet>  $query
     * @return Builder<Bet>
     */
    public function search(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        $contains = fn (Builder $query, string $column): Builder => $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);

        return $query->where(function (Builder $matches) use ($contains): void {
            $contains($matches, 'bets.notes');

            foreach (self::SEARCHED_COLUMNS as $relation => $columns) {
                $matches->orWhereHas($relation, function (Builder $related) use ($contains, $columns): void {
                    $related->where(function (Builder $any) use ($contains, $columns): void {
                        foreach ($columns as $column) {
                            $contains($any, $column);
                        }
                    });
                });
            }
        });
    }

    /**
     * Limit the bets to those of one import (`import_batch`). An empty batch id changes
     * nothing; an id that isn't one of the user's imports matches no bets.
     *
     * @param  Builder<Bet>  $query
     * @return Builder<Bet>
     */
    public function inImportBatch(Builder $query, string $batchId): Builder
    {
        return $batchId === '' ? $query : $query->where('bets.import_batch', $batchId);
    }

    /**
     * The user's imports that still have bets, newest first: each batch with the time its
     * first bet was saved and its number of bets.
     *
     * @return list<ImportBatch>
     */
    public function importBatches(User $user): array
    {
        return $user->bets()->getQuery()->toBase()
            ->whereNotNull('import_batch')
            ->groupBy('import_batch')
            ->selectRaw('import_batch, MIN(created_at) AS imported_at, COUNT(*) AS bet_count')
            ->orderByDesc('imported_at')
            ->orderBy('import_batch')
            ->get()
            ->map(fn (stdClass $row): ImportBatch => new ImportBatch(
                (string) $row->import_batch,
                CarbonImmutable::parse((string) $row->imported_at, 'UTC'),
                (int) $row->bet_count,
            ))
            ->values()
            ->all();
    }

    /**
     * Limit the column to instants from one (included) to another (excluded), both UTC:
     * the start of the first local day and the start of the local day after the last.
     *
     * @param  Builder<Bet>  $query
     * @return Builder<Bet>
     */
    private function between(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $query) => $query->where($column, '>=', $from))
            ->when($until !== null, fn (Builder $query) => $query->where($column, '<', $until));
    }
}
