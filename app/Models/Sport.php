<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\SportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sport; competitions and teams are kept per sport.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Sport extends Model
{
    /** @use HasFactory<SportFactory> */
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
     * @return HasMany<Competition, $this>
     */
    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * A sport is in use while it has competitions or teams (deleting it would delete them).
     *
     * @return list<string>
     */
    public static function usageRelations(): array
    {
        return ['competitions', 'teams'];
    }
}
