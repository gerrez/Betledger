<?php

namespace App\Livewire\Lists;

use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The lists a user builds up while recording bets, as tabs on the Lists page.
 */
enum ListType: string
{
    case Sports = 'sports';
    case Competitions = 'competitions';
    case Teams = 'teams';
    case Markets = 'markets';
    case Tipsters = 'tipsters';
    case Tags = 'tags';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * One entry of the list in lower case, e.g. "competition".
     */
    public function singular(): string
    {
        return rtrim($this->value, 's');
    }

    /**
     * Whether entries are kept per sport (names are unique within a sport).
     */
    public function isPerSport(): bool
    {
        return $this === self::Competitions || $this === self::Teams;
    }

    /**
     * Explains on the Lists page when an entry of this list can be deleted.
     */
    public function usageNote(): string
    {
        $note = 'Only entries that nothing uses can be deleted.';

        return $this === self::Sports ? "A sport is in use while it has competitions or teams. {$note}" : $note;
    }

    /**
     * The user's deleted bets that use each entry of this list, as bet ids by entry id.
     * They don't keep an entry in use, but they still refer to it, so deleting the entry
     * deletes them for good (a restored bet must not lose its sport, team or tag).
     * Deleted bets are few (they are pruned after 30 days), so they are loaded at once.
     *
     * @return array<int, list<int>>
     */
    public function deletedBetIds(User $user): array
    {
        $bets = $user->bets()->onlyTrashed()->with(['selections.teams', 'tags'])->get();
        $betIds = [];

        foreach ($bets as $bet) {
            $entryIds = match ($this) {
                self::Sports => $bet->selections->pluck('sport_id')->all(),
                self::Competitions => $bet->selections->pluck('competition_id')->all(),
                self::Teams => $bet->selections->flatMap(fn (Selection $selection): array => $selection->teams->modelKeys())->all(),
                self::Markets => $bet->selections->pluck('market_id')->all(),
                self::Tipsters => [$bet->tipster_id],
                self::Tags => $bet->tags->modelKeys(),
            };

            foreach (array_unique(array_filter($entryIds, fn (mixed $id): bool => is_int($id))) as $entryId) {
                $betIds[$entryId][] = $bet->id;
            }
        }

        return $betIds;
    }

    /**
     * The user's entries of this list.
     *
     * @return HasMany<Sport, User>|HasMany<Competition, User>|HasMany<Team, User>|HasMany<Market, User>|HasMany<Tipster, User>|HasMany<Tag, User>
     */
    public function entries(User $user): HasMany
    {
        return match ($this) {
            self::Sports => $user->sports(),
            self::Competitions => $user->competitions(),
            self::Teams => $user->teams(),
            self::Markets => $user->markets(),
            self::Tipsters => $user->tipsters(),
            self::Tags => $user->tags(),
        };
    }
}
