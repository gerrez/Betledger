<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A market type, e.g. 1X2, Asian handicap or Over/Under.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Market extends Model
{
    /** @use HasFactory<MarketFactory> */
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
     * Selections of bets that aren't deleted: a deleted bet doesn't keep the entry in use.
     *
     * @return HasMany<Selection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class)->whereHas('bet');
    }

    /**
     * In use while a selection refers to it.
     *
     * @return list<string>
     */
    public static function usageRelations(): array
    {
        return ['selections'];
    }
}
