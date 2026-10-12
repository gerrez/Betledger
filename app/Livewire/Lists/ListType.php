<?php

namespace App\Livewire\Lists;

use App\Models\Competition;
use App\Models\Market;
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
