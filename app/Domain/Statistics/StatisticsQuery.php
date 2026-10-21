<?php

namespace App\Domain\Statistics;

use App\Domain\BetStatus;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Load a user's bets that pass a filter, reduced to what statistics need (BetFigures).
 * Only the user's own bets are ever read.
 */
final class StatisticsQuery
{
    public function __construct(private SettlementCalculator $calculator) {}

    /**
     * @return list<BetFigures>
     */
    public function bets(User $user, StatisticsFilter $filter): array
    {
        $bets = $this->filtered($user, $filter)
            ->with(['bookmaker', 'tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams'])
            ->get();

        return array_values($bets->map(fn (Bet $bet): BetFigures => $this->figures($bet))->all());
    }

    /**
     * @return Builder<Bet>
     */
    private function filtered(User $user, StatisticsFilter $filter): Builder
    {
        $hasSelection = fn (string $column, ?int $id) => fn (Builder $query) => $query->whereHas('selections', fn (Builder $selections) => $selections->where($column, $id));

        return $user->bets()->getQuery()
            ->when($filter->status !== null, fn (Builder $query) => $query->where('status', $filter->status))
            ->when($filter->from !== null || $filter->to !== null, fn (Builder $query) => $query->where(fn (Builder $dates) => $dates
                ->where(fn (Builder $settled) => $this->between($settled->where('status', BetStatus::Settled), 'settled_at', $filter->from, $filter->to))
                ->orWhere(fn (Builder $open) => $this->between($open->where('status', BetStatus::Open), 'placed_at', $filter->from, $filter->to))))
            ->when($filter->bookmakerId !== null, fn (Builder $query) => $query->where('bookmaker_id', $filter->bookmakerId))
            ->when($filter->tipsterId !== null, fn (Builder $query) => $query->where('tipster_id', $filter->tipsterId))
            ->when($filter->type !== null, fn (Builder $query) => $query->where('type', $filter->type))
            ->when(! $filter->includeFreeBets, fn (Builder $query) => $query->where('is_free_bet', false))
            ->when($filter->tagId !== null, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($filter->tagId)))
            ->when($filter->sportId !== null, $hasSelection('sport_id', $filter->sportId))
            ->when($filter->competitionId !== null, $hasSelection('competition_id', $filter->competitionId))
            ->when($filter->marketId !== null, $hasSelection('market_id', $filter->marketId))
            ->when($filter->teamId !== null, fn (Builder $query) => $query->whereHas('selections.teams', fn (Builder $teams) => $teams->whereKey($filter->teamId)));
    }

    /**
     * Limit the column to whole days from one date to another, both included.
     *
     * @param  Builder<Bet>  $query
     * @return Builder<Bet>
     */
    private function between(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $query) => $query->where($column, '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where($column, '<', $to?->addDay()));
    }

    private function figures(Bet $bet): BetFigures
    {
        $selections = $bet->selections;
        $legs = array_values($selections->map(fn (Selection $selection): Leg => new Leg($selection->odds, $selection->result))->all());

        return new BetFigures(
            stake: $bet->stake,
            profit: $bet->isSettled() ? $bet->profit : null,
            factor: $bet->isSettled() ? $this->calculator->factor($legs) : null,
            exchangeRate: $bet->exchange_rate,
            type: $bet->type,
            isFreeBet: $bet->is_free_bet,
            totalOdds: $bet->total_odds,
            settledAt: $bet->isSettled() ? $bet->settled_at?->toImmutable() : null,
            closingOdds: array_values($selections->map(fn (Selection $selection): ?string => $selection->closing_odds)->all()),
            groups: [
                Dimension::Bookmaker->value => [new Group($bet->bookmaker->id, $bet->bookmaker->name)],
                Dimension::Tipster->value => $bet->tipster === null ? [] : [new Group($bet->tipster->id, $bet->tipster->name)],
                Dimension::Tag->value => array_values($bet->tags->map(fn (Tag $tag): Group => new Group($tag->id, $tag->name))->all()),
                Dimension::Sport->value => array_values($selections->map(fn (Selection $selection): Group => new Group($selection->sport->id, $selection->sport->name))->all()),
                Dimension::Competition->value => array_values($selections->map(fn (Selection $selection): Group => $selection->competition === null ? Group::none() : new Group($selection->competition->id, $selection->competition->name))->all()),
                Dimension::Market->value => array_values($selections->map(fn (Selection $selection): Group => $selection->market === null ? Group::none() : new Group($selection->market->id, $selection->market->name))->all()),
                Dimension::Team->value => array_merge(...array_values($selections->map(fn (Selection $selection): array => $this->teamGroups($selection))->all())),
            ],
        );
    }

    /**
     * The participants of the selection's event, or none (e.g. an outright).
     *
     * @return list<Group>
     */
    private function teamGroups(Selection $selection): array
    {
        if ($selection->teams->isEmpty()) {
            return [Group::none()];
        }

        return array_values($selection->teams->map(fn (Team $team): Group => new Group($team->id, $team->name))->all());
    }
}
