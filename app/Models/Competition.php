<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A league, cup or tournament. It always belongs to one of its owner's own sports.
 *
 * @property int $id
 * @property int $user_id
 * @property int $sport_id
 * @property string $name
 * @property-read Sport $sport
 */
class Competition extends Model
{
    /** @use HasFactory<CompetitionFactory> */
    use HasFactory, IsListEntry;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sport_id',
        'name',
    ];

    /**
     * @return BelongsTo<Sport, $this>
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
