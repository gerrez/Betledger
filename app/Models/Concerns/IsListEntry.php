<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in one of the user's lists: sports, competitions, teams, markets, tipsters
 * and tags. Entries are renamed on the Lists page and can be deleted only while unused.
 */
trait IsListEntry
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The relations whose records keep this entry in use.
     *
     * @return list<string>
     */
    public static function usageRelations(): array
    {
        return [];
    }

    /**
     * Whether anything refers to this entry. Uses the `<relation>_exists` attributes
     * when the query loaded them with `withExists(usageRelations())`.
     */
    public function isInUse(): bool
    {
        foreach (static::usageRelations() as $relation) {
            if (! array_key_exists("{$relation}_exists", $this->attributes)) {
                $this->loadExists($relation);
            }

            if ($this->getAttribute("{$relation}_exists")) {
                return true;
            }
        }

        return false;
    }
}
