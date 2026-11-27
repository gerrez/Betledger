<?php

namespace App\Domain\Bets;

use App\Models\Bet;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Change many of a user's bets at once: delete them, or add or remove a tag. The bets
 * are given as ids, which are looked up again through the user's own bets, so an id of
 * another user's bet (or of no bet) is ignored. Each action runs in one transaction and
 * returns the number of bets it changed.
 */
final class BulkBetActions
{
    /**
     * How many ids go into one `WHERE id IN (…)`, well below what SQLite and Postgres
     * accept as bound parameters.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Delete the bets: they move to Settings → Deleted bets (soft delete) with their
     * selections, teams and tags, and can be restored until they are pruned.
     *
     * @param  list<int>  $betIds
     */
    public function delete(User $user, array $betIds): int
    {
        return DB::transaction(fn (): int => $this->eachChunk(
            $betIds,
            fn (array $chunk): int => (int) $this->bets($user, $chunk)->delete(),
        ));
    }

    /**
     * Give the bets the tag; bets that already have it are left as they are and not
     * counted.
     *
     * @param  list<int>  $betIds
     */
    public function addTag(User $user, Tag $tag, array $betIds): int
    {
        $this->ensureOwnTag($user, $tag);

        return DB::transaction(fn (): int => $this->eachChunk($betIds, function (array $chunk) use ($user, $tag): int {
            $untagged = $this->bets($user, $chunk)
                ->whereDoesntHave('tags', fn (Builder $tags) => $tags->whereKey($tag->id))
                ->pluck('id')
                ->all();

            $tag->bets()->attach($untagged);

            return count($untagged);
        }));
    }

    /**
     * Take the tag off the bets; bets without it are not counted.
     *
     * @param  list<int>  $betIds
     */
    public function removeTag(User $user, Tag $tag, array $betIds): int
    {
        $this->ensureOwnTag($user, $tag);

        return DB::transaction(fn (): int => $this->eachChunk(
            $betIds,
            fn (array $chunk): int => $tag->bets()->detach($this->bets($user, $chunk)->pluck('id')->all()),
        ));
    }

    /**
     * The user's bets among the ids.
     *
     * @param  list<int>  $ids
     * @return Builder<Bet>
     */
    private function bets(User $user, array $ids): Builder
    {
        return $user->bets()->getQuery()->whereKey($ids);
    }

    /**
     * Run the action on the distinct ids, a chunk at a time, and add up what it returns.
     *
     * @param  list<int>  $ids
     * @param  callable(list<int>): int  $action
     */
    private function eachChunk(array $ids, callable $action): int
    {
        $changed = 0;

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK_SIZE) as $chunk) {
            $changed += $action($chunk);
        }

        return $changed;
    }

    private function ensureOwnTag(User $user, Tag $tag): void
    {
        if ($tag->user_id !== $user->id) {
            throw new InvalidArgumentException('The tag must be one of the user\'s own tags.');
        }
    }
}
