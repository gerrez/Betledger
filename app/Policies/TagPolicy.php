<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    /**
     * Determine whether the user can rename the tag.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $user->id === $tag->user_id;
    }

    /**
     * Determine whether the user can delete the tag (the caller checks that it is unused).
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $user->id === $tag->user_id;
    }
}
