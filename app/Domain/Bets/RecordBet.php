<?php

namespace App\Domain\Bets;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\ExchangeRate;
use App\Domain\Odds;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Save a new bet for a user, or the changes to an existing one. Currency is copied from
 * the bookmaker; list entries are matched by name to the user's own entries (ignoring
 * case) or added to their lists, so a bet can never refer to another user's records.
 */
final class RecordBet
{
    /**
     * Added to the positions of a bet's selections while they are renumbered, so no two
     * of them ever share a position (`selections` is unique on bet and position).
     */
    private const POSITION_OFFSET = 1000;

    public function __construct(private SettlementCalculator $calculator) {}

    public function handle(User $user, BetData $data): Bet
    {
        return DB::transaction(function () use ($user, $data): Bet {
            $bet = new Bet($this->betAttributes($user, $data));
            $bet->user()->associate($user);
            $bet->save();

            $bet->tags()->attach($this->tagIds($user, $data));

            foreach ($data->selections as $index => $selectionData) {
                $sport = $this->findOrCreate($user->sports(), $selectionData->sport);
                $selection = $bet->selections()->create($this->selectionAttributes($user, $selectionData, $sport, $index + 1));

                $selection->teams()->attach($this->teamIds($user, $selectionData, $sport));
            }

            return $bet;
        });
    }

    /**
     * Replace a bet's details and selections with the edited ones. Selections that are
     * kept (by id) keep their result; the others are removed, and new ones are pending.
     * When the stake, the free bet flag or the selections' odds or results change, the
     * bet is settled again by the settlement rules: it may become open or settled, and a
     * payout the user entered by hand is replaced by the calculated one.
     */
    public function update(Bet $bet, BetData $data): Bet
    {
        return DB::transaction(function () use ($bet, $data): Bet {
            $user = $bet->user;
            $settlementInputs = $this->settlementInputs($bet->load('selections'));

            $bet->fill($this->betAttributes($user, $data))->save();
            $bet->tags()->sync($this->tagIds($user, $data));

            $keptIds = array_values(array_filter(array_map(fn (SelectionData $selection): ?int => $selection->id, $data->selections)));
            $bet->selections()->whereNotIn('id', $keptIds)->delete();
            Selection::query()->where('bet_id', $bet->id)->increment('position', self::POSITION_OFFSET);

            foreach ($data->selections as $index => $selectionData) {
                $sport = $this->findOrCreate($user->sports(), $selectionData->sport);
                $attributes = $this->selectionAttributes($user, $selectionData, $sport, $index + 1);

                if ($selectionData->id === null) {
                    $selection = $bet->selections()->create($attributes);
                } else {
                    $selection = $bet->selections()->findOrFail($selectionData->id);
                    $selection->update($attributes);
                }

                $selection->teams()->sync($this->teamIds($user, $selectionData, $sport));
            }

            $bet->unsetRelation('selections');

            if ($this->settlementInputs($bet) !== $settlementInputs) {
                $this->settle($bet);
            }

            return $bet;
        });
    }

    /**
     * Status, payout, profit and settlement date from the selections' results. A bet
     * that was already settled keeps its settlement date.
     */
    private function settle(Bet $bet): void
    {
        $legs = $bet->selections->map(fn (Selection $selection): Leg => new Leg($selection->odds, $selection->result))->all();
        $settlement = $this->calculator->settle($bet->stake, $bet->is_free_bet, array_values($legs));

        $bet->status = $settlement === null ? BetStatus::Open : BetStatus::Settled;
        $bet->settled_at = $settlement === null ? null : ($bet->settled_at ?? now());
        $bet->payout = $settlement?->payout;
        $bet->profit = $settlement?->profit;
        $bet->payout_is_manual = false;
        $bet->save();
    }

    /**
     * What the bet's settlement depends on: stake, free bet, and each selection's odds
     * and result in order.
     *
     * @return array{stake: int, is_free_bet: bool, legs: list<array{string, string|null}>}
     */
    private function settlementInputs(Bet $bet): array
    {
        return [
            'stake' => $bet->stake,
            'is_free_bet' => $bet->is_free_bet,
            'legs' => array_values($bet->selections->map(fn (Selection $selection): array => [
                Odds::format($selection->odds),
                $selection->result?->value,
            ])->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function betAttributes(User $user, BetData $data): array
    {
        $bookmaker = $user->bookmakers()->findOrFail($data->bookmakerId);

        return [
            'bookmaker_id' => $bookmaker->id,
            'tipster_id' => $data->tipster === null ? null : $this->findOrCreate($user->tipsters(), $data->tipster)->id,
            'type' => BetType::forSelectionCount(count($data->selections)),
            'placed_at' => $data->placedAt,
            'stake' => $data->stake,
            'currency' => $bookmaker->currency,
            'exchange_rate' => $bookmaker->currency === $user->base_currency ? ExchangeRate::SAME_CURRENCY : $data->exchangeRate,
            'is_free_bet' => $data->isFreeBet,
            'total_odds' => Odds::total(array_map(fn (SelectionData $selection): string => $selection->odds, $data->selections)),
            'notes' => $data->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionAttributes(User $user, SelectionData $data, Sport $sport, int $position): array
    {
        return [
            'position' => $position,
            'sport_id' => $sport->id,
            'competition_id' => $data->competition === null ? null : $this->findOrCreate($user->competitions(), $data->competition, $sport)->id,
            'market_id' => $data->market === null ? null : $this->findOrCreate($user->markets(), $data->market)->id,
            'event_name' => $data->eventName,
            'event_starts_at' => $data->eventStartsAt,
            'pick' => $data->pick,
            'odds' => $data->odds,
            'closing_odds' => $data->closingOdds,
        ];
    }

    /**
     * @return list<int>
     */
    private function tagIds(User $user, BetData $data): array
    {
        return array_map(
            fn (string $name): int => $this->findOrCreate($user->tags(), $name)->id,
            $this->uniqueNames($data->tags),
        );
    }

    /**
     * @return list<int>
     */
    private function teamIds(User $user, SelectionData $data, Sport $sport): array
    {
        return array_map(
            fn (string $name): int => $this->findOrCreate($user->teams(), $name, $sport)->id,
            $this->uniqueNames($data->teams),
        );
    }

    /**
     * The user's entry with this name, ignoring case ("arsenal" finds "Arsenal"), or a
     * new one. Competitions and teams are looked up and added within the sport.
     *
     * @template TEntry of Sport|Competition|Team|Market|Tipster|Tag
     *
     * @param  HasMany<TEntry, User>  $entries
     * @return TEntry
     */
    private function findOrCreate(HasMany $entries, string $name, ?Sport $sport = null): Sport|Competition|Team|Market|Tipster|Tag
    {
        $attributes = $sport === null ? [] : ['sport_id' => $sport->id];

        $existing = (clone $entries)->where($attributes)->get()
            ->first(fn (Sport|Competition|Team|Market|Tipster|Tag $entry): bool => $this->sameName($entry->name, $name));

        return $existing ?? $entries->create([...$attributes, 'name' => $name]);
    }

    /**
     * The names without duplicates that differ only in case, keeping the first spelling.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function uniqueNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            foreach ($unique as $kept) {
                if ($this->sameName($kept, $name)) {
                    continue 2;
                }
            }

            $unique[] = $name;
        }

        return $unique;
    }

    private function sameName(string $left, string $right): bool
    {
        return mb_strtolower($left) === mb_strtolower($right);
    }
}
