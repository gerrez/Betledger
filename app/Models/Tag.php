<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A free label for bets.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, IsListEntry;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<Bet, $this, OwnedPivot>
     */
    public function bets(): BelongsToMany
    {
        return $this->belongsToMany(Bet::class)->using(OwnedPivot::class);
    }

    /**
     * In use while a bet refers to it.
     *
     * @return list<string>
     */
    public static function usageRelations(): array
    {
        return ['bets'];
    }
}
