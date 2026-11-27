<?php

namespace App\Domain\Statistics;

use App\Domain\Bets\BetFilterQuery;
use App\Domain\LocalTime;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;

/**
 * Load a user's bets that pass a filter, reduced to what statistics need (BetFigures).
 * The filter's conditions are BetFilterQuery's, shared with the bets list: a date range
 * takes settled bets by their event date (`event_at`) and open bets by their placed
 * date, each day being that day in the user's time zone. The event date
 * handed to BetFigures is in the user's zone too, so profit over time buckets by local
 * days without knowing about zones.
 * Only the user's own bets are ever read.
 */
final class StatisticsQuery
{
    public function __construct(private SettlementCalculator $calculator, private BetFilterQuery $betFilter) {}

    /**
     * @return list<BetFigures>
     */
    public function bets(User $user, StatisticsFilter $filter): array
    {
        $bets = $this->betFilter->filtered($user, $filter)
            ->with(['bookmaker', 'tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams'])
            ->get();

        $localTime = $user->localTime();

        return array_values($bets->map(fn (Bet $bet): BetFigures => $this->figures($bet, $localTime))->all());
    }

    private function figures(Bet $bet, LocalTime $localTime): BetFigures
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
            eventAt: $localTime->toLocal($bet->event_at),
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
