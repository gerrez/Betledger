<?php

namespace App\Models;

use App\Domain\Settlement\SelectionResult;
use Database\Factories\SelectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One leg of a bet: the event, what was backed, the odds and the result (null while
 * pending). It belongs to the bet's owner, who is filled in from the bet when created.
 *
 * @property int $id
 * @property int $user_id
 * @property int $bet_id
 * @property int $position order within the bet, from 1
 * @property int $sport_id
 * @property int|null $competition_id
 * @property int|null $market_id
 * @property string $event_name
 * @property Carbon|null $event_starts_at
 * @property string $pick
 * @property string $odds 3 decimal places
 * @property string|null $closing_odds 3 decimal places
 * @property SelectionResult|null $result
 * @property-read Bet $bet
 * @property-read Sport $sport
 * @property-read Competition|null $competition
 * @property-read Market|null $market
 * @property-read Collection<int, Team> $teams
 */
class Selection extends Model
{
    /** @use HasFactory<SelectionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'position',
        'sport_id',
        'competition_id',
        'market_id',
        'event_name',
        'event_starts_at',
        'pick',
        'odds',
        'closing_odds',
        'result',
    ];

    protected static function booted(): void
    {
        static::creating(function (Selection $selection): void {
            $selection->user_id ??= $selection->bet->user_id;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'event_starts_at' => 'datetime',
            'odds' => 'decimal:3',
            'closing_odds' => 'decimal:3',
            'result' => SelectionResult::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Bet, $this>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /**
     * @return BelongsTo<Sport, $this>
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * The participants of the event.
     *
     * @return BelongsToMany<Team, $this, OwnedPivot>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->using(OwnedPivot::class);
    }
}
