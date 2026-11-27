<?php

namespace App\Domain\Statistics;

use App\Domain\Bets\BetFilterQuery;
use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Decimal;
use App\Domain\LocalTime;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Load a user's bets that pass a filter, reduced to what statistics need (BetFigures).
 * The filter's conditions are BetFilterQuery's, shared with the bets list: a date range
 * takes settled bets by their event date (`event_at`) and open bets by their placed
 * date, each day being that day in the user's time zone. The event date
 * handed to BetFigures is in the user's zone too, so profit over time buckets by local
 * days without knowing about zones.
 * Only the user's own bets are ever read.
 *
 * The bets, their selections (with sport, competition and market names), teams and tags
 * are read as plain rows, a handful of queries however many bets match, without building
 * a model per bet and selection: at a few thousand bets the models and their casts took
 * most of the statistics page's time. Decimal columns are read at their column's scale,
 * as the models' decimal casts do, so the figures are the same.
 */
final class StatisticsQuery
{
    /** The scale of `bets.exchange_rate`, `bets.total_odds` and `selections.odds`/`closing_odds`. */
    private const EXCHANGE_RATE_PLACES = 8;

    private const TOTAL_ODDS_PLACES = 4;

    private const ODDS_PLACES = 3;

    public function __construct(private SettlementCalculator $calculator, private BetFilterQuery $betFilter) {}

    /**
     * @return list<BetFigures>
     */
    public function bets(User $user, StatisticsFilter $filter): array
    {
        $bets = $this->betFilter->filtered($user, $filter)->toBase()
            ->select(['bets.id', 'bets.bookmaker_id', 'bets.tipster_id', 'bets.type', 'bets.stake', 'bets.exchange_rate', 'bets.is_free_bet', 'bets.total_odds', 'bets.status', 'bets.profit', 'bets.event_at'])
            ->orderBy('bets.id')
            ->get();

        if ($bets->isEmpty()) {
            return [];
        }

        // One Group per list entry, shared by all the bets that count under it.
        /** @var array<string, Group> $pool */
        $pool = [];
        $none = Group::none();
        $group = function (Dimension $dimension, mixed $id, mixed $name) use (&$pool, $none): Group {
            if ($id === null) {
                return $none;
            }

            return $pool["{$dimension->value}:{$id}"] ??= new Group((int) $id, (string) $name);
        };

        /** @var list<int> $betIds */
        $betIds = array_values($bets->map(fn (stdClass $bet): int => (int) $bet->id)->all());
        $selections = $this->selections($user, $betIds);
        $teams = $this->teams($user, array_map(fn (stdClass $selection): int => (int) $selection->id, array_merge(...array_values($selections))), $group);
        $tags = $this->tags($user, $betIds, $group);
        $bookmakers = $this->names($user->bookmakers()->getQuery()->toBase());
        $tipsters = $this->names($user->tipsters()->getQuery()->toBase());
        $localTime = $user->localTime();

        return array_values($bets->map(fn (stdClass $bet): BetFigures => $this->figures(
            $bet,
            $selections[(int) $bet->id] ?? [],
            $teams,
            $tags[(int) $bet->id] ?? [],
            $bookmakers,
            $tipsters,
            $group,
            $localTime,
        ))->all());
    }

    /**
     * The user's open bets and their stake, from one grouped query that loads no bets,
     * for pages that need nothing else about the open bets.
     */
    public function openStake(User $user): OpenStake
    {
        $groups = $user->bets()->getQuery()
            ->where('status', BetStatus::Open)
            ->select(['stake', 'exchange_rate', 'is_free_bet'])
            ->selectRaw('COUNT(*) AS bet_count')
            ->groupBy('stake', 'exchange_rate', 'is_free_bet')
            ->get();

        return OpenStake::fromGroups($groups->map(fn (Bet $group): array => [
            'stake' => $group->stake,
            'exchangeRate' => $group->exchange_rate,
            'isFreeBet' => $group->is_free_bet,
            'bets' => (int) $group->getAttribute('bet_count'),
        ]));
    }

    /**
     * @param  list<stdClass>  $selections  the bet's selections in order
     * @param  array<int, list<Group>>  $teams  by selection id
     * @param  list<Group>  $tags
     * @param  array<int, string>  $bookmakers  names by id
     * @param  array<int, string>  $tipsters  names by id
     * @param  Closure(Dimension, mixed, mixed): Group  $group  the shared group of a list entry's id and name, none for a null id
     */
    private function figures(stdClass $bet, array $selections, array $teams, array $tags, array $bookmakers, array $tipsters, Closure $group, LocalTime $localTime): BetFigures
    {
        $isSettled = $bet->status === BetStatus::Settled->value;
        $legs = array_map(fn (stdClass $selection): Leg => new Leg(
            $this->decimal($selection->odds, self::ODDS_PLACES),
            $selection->result === null ? null : SelectionResult::from((string) $selection->result),
        ), $selections);

        return new BetFigures(
            stake: (int) $bet->stake,
            profit: $isSettled ? (int) $bet->profit : null,
            factor: $isSettled ? $this->calculator->factor($legs) : null,
            exchangeRate: $this->decimal($bet->exchange_rate, self::EXCHANGE_RATE_PLACES),
            type: BetType::from((string) $bet->type),
            isFreeBet: (bool) $bet->is_free_bet,
            totalOdds: $this->decimal($bet->total_odds, self::TOTAL_ODDS_PLACES),
            closingOdds: array_map(fn (stdClass $selection): ?string => $selection->closing_odds === null ? null : $this->decimal($selection->closing_odds, self::ODDS_PLACES), $selections),
            groups: [
                Dimension::Bookmaker->value => [$group(Dimension::Bookmaker, $bet->bookmaker_id, $bookmakers[(int) $bet->bookmaker_id])],
                Dimension::Tipster->value => $bet->tipster_id === null ? [] : [$group(Dimension::Tipster, $bet->tipster_id, $tipsters[(int) $bet->tipster_id])],
                Dimension::Tag->value => $tags,
                Dimension::Sport->value => array_map(fn (stdClass $selection): Group => $group(Dimension::Sport, $selection->sport_id, $selection->sport_name), $selections),
                Dimension::Competition->value => array_map(fn (stdClass $selection): Group => $group(Dimension::Competition, $selection->competition_id, $selection->competition_name), $selections),
                Dimension::Market->value => array_map(fn (stdClass $selection): Group => $group(Dimension::Market, $selection->market_id, $selection->market_name), $selections),
                Dimension::Team->value => array_merge(...array_map(fn (stdClass $selection): array => $teams[(int) $selection->id] ?? [$group(Dimension::Team, null, null)], $selections)),
            ],
            eventAt: $localTime->toLocal(CarbonImmutable::parse((string) $bet->event_at, 'UTC')),
            betId: (int) $bet->id,
        );
    }

    /**
     * The selections of the bets with their sport, competition and market, by bet id, in
     * order.
     *
     * @param  list<int>  $betIds
     * @return array<int, list<stdClass>>
     */
    private function selections(User $user, array $betIds): array
    {
        return $this->byKey($user->selections()->getQuery()->toBase()
            ->join('sports', 'sports.id', '=', 'selections.sport_id')
            ->leftJoin('competitions', 'competitions.id', '=', 'selections.competition_id')
            ->leftJoin('markets', 'markets.id', '=', 'selections.market_id')
            ->whereIntegerInRaw('selections.bet_id', $betIds)
            ->orderBy('selections.bet_id')
            ->orderBy('selections.position')
            ->get([
                'selections.id', 'selections.bet_id', 'selections.odds', 'selections.closing_odds', 'selections.result',
                'selections.sport_id', 'sports.name as sport_name',
                'selections.competition_id', 'competitions.name as competition_name',
                'selections.market_id', 'markets.name as market_name',
            ]), 'bet_id');
    }

    /**
     * The participants of the selections' events by selection id; a selection without
     * any (e.g. an outright) is left out.
     *
     * @param  list<int>  $selectionIds
     * @param  Closure(Dimension, mixed, mixed): Group  $group
     * @return array<int, list<Group>>
     */
    private function teams(User $user, array $selectionIds, Closure $group): array
    {
        $rows = $user->teams()->getQuery()->toBase()
            ->join('selection_team', 'selection_team.team_id', '=', 'teams.id')
            ->whereIntegerInRaw('selection_team.selection_id', $selectionIds)
            ->orderBy('teams.id')
            ->get(['selection_team.selection_id', 'teams.id', 'teams.name']);

        return array_map(fn (array $teams): array => array_map(fn (stdClass $team): Group => $group(Dimension::Team, $team->id, $team->name), $teams), $this->byKey($rows, 'selection_id'));
    }

    /**
     * The tags of the bets by bet id; a bet without tags is left out.
     *
     * @param  list<int>  $betIds
     * @param  Closure(Dimension, mixed, mixed): Group  $group
     * @return array<int, list<Group>>
     */
    private function tags(User $user, array $betIds, Closure $group): array
    {
        $rows = $user->tags()->getQuery()->toBase()
            ->join('bet_tag', 'bet_tag.tag_id', '=', 'tags.id')
            ->whereIntegerInRaw('bet_tag.bet_id', $betIds)
            ->orderBy('tags.id')
            ->get(['bet_tag.bet_id', 'tags.id', 'tags.name']);

        return array_map(fn (array $tags): array => array_map(fn (stdClass $tag): Group => $group(Dimension::Tag, $tag->id, $tag->name), $tags), $this->byKey($rows, 'bet_id'));
    }

    /**
     * @param  Builder  $query  a list owned by the user (bookmakers, tipsters)
     * @return array<int, string>
     */
    private function names(Builder $query): array
    {
        $names = [];

        foreach ($query->get(['id', 'name']) as $row) {
            $names[(int) $row->id] = (string) $row->name;
        }

        return $names;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, list<stdClass>>
     */
    private function byKey(Collection $rows, string $column): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->{$column}][] = $row;
        }

        return $grouped;
    }

    /**
     * A decimal column's value as a string at the column's scale, whichever type the
     * driver returns: a string from Postgres, a number from SQLite (which stores decimals
     * as integers or floats). The scale holds every digit the column can store, so a
     * float is formatted back to the value that was saved.
     */
    private function decimal(mixed $value, int $places): string
    {
        return is_string($value) ? Decimal::round($value, $places) : sprintf("%.{$places}F", $value);
    }
}
