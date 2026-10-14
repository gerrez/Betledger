<?php

namespace App\Domain\Bets;

use App\Domain\BetType;
use App\Domain\ExchangeRate;
use App\Domain\Odds;
use App\Models\Bet;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Save a new bet for a user. Currency is copied from the bookmaker; list entries are
 * matched by name to the user's own entries (ignoring case) or added to their lists, so
 * a bet can never refer to another user's records.
 */
final class RecordBet
{
    public function handle(User $user, BetData $data): Bet
    {
        return DB::transaction(function () use ($user, $data): Bet {
            $bookmaker = $user->bookmakers()->findOrFail($data->bookmakerId);

            $bet = new Bet([
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
            ]);
            $bet->user()->associate($user);
            $bet->save();

            $bet->tags()->attach(array_map(
                fn (string $name): int => $this->findOrCreate($user->tags(), $name)->id,
                $this->uniqueNames($data->tags),
            ));

            foreach ($data->selections as $index => $selectionData) {
                $sport = $this->findOrCreate($user->sports(), $selectionData->sport);

                $selection = $bet->selections()->create([
                    'position' => $index + 1,
                    'sport_id' => $sport->id,
                    'competition_id' => $selectionData->competition === null ? null : $this->findOrCreate($user->competitions(), $selectionData->competition, $sport)->id,
                    'market_id' => $selectionData->market === null ? null : $this->findOrCreate($user->markets(), $selectionData->market)->id,
                    'event_name' => $selectionData->eventName,
                    'event_starts_at' => $selectionData->eventStartsAt,
                    'pick' => $selectionData->pick,
                    'odds' => $selectionData->odds,
                    'closing_odds' => $selectionData->closingOdds,
                ]);

                $selection->teams()->attach(array_map(
                    fn (string $name): int => $this->findOrCreate($user->teams(), $name, $sport)->id,
                    $this->uniqueNames($selectionData->teams),
                ));
            }

            return $bet;
        });
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
