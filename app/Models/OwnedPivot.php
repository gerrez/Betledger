<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row of a pivot table that stores its owner (`bet_tag`, `selection_team`), so the
 * database can refuse linking records of two different users. Attaching through the
 * relation fills in the owner from the parent model.
 *
 * @property int $user_id
 */
class OwnedPivot extends Pivot
{
    protected static function booted(): void
    {
        static::creating(function (OwnedPivot $pivot): void {
            $pivot->user_id ??= $pivot->pivotParent->getAttribute('user_id');
        });
    }
}
