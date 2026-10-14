<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\TipsterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tipster, model or strategy a bet followed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Tipster extends Model
{
    /** @use HasFactory<TipsterFactory> */
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
     * @return HasMany<Bet, $this>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
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
