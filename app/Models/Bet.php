<?php

namespace App\Models;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use Database\Factories\BetFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bet placed at a bookmaker: one stake, one or more selections. Amounts are integers
 * in minor units of the bet's currency; odds and the exchange rate are decimal strings.
 *
 * @property int $id
 * @property int $user_id
 * @property int $bookmaker_id
 * @property int|null $tipster_id
 * @property BetType $type
 * @property Carbon $placed_at
 * @property int $stake minor units
 * @property Currency $currency copied from the bookmaker when saved
 * @property string $exchange_rate 1 unit of the bet's currency = this many units of the user's base currency, copied from the bookmaker when saved
 * @property bool $is_free_bet
 * @property string $total_odds product of the selection odds, 4 decimal places
 * @property BetStatus $status
 * @property Carbon|null $settled_at
 * @property int|null $payout minor units
 * @property bool $payout_is_manual
 * @property int|null $profit minor units
 * @property string|null $notes
 * @property-read User $user
 * @property-read Bookmaker $bookmaker
 * @property-read Tipster|null $tipster
 * @property-read Collection<int, Selection> $selections
 * @property-read Collection<int, Tag> $tags
 */
class Bet extends Model
{
    /** @use HasFactory<BetFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_free_bet' => false,
        'status' => 'open',
        'payout_is_manual' => false,
    ];

    /**
     * The attributes that are mass assignable. The owner, status and settlement
     * columns are set by the code that creates and settles bets.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bookmaker_id',
        'tipster_id',
        'type',
        'placed_at',
        'stake',
        'currency',
        'exchange_rate',
        'is_free_bet',
        'total_odds',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BetType::class,
            'placed_at' => 'datetime',
            'stake' => 'integer',
            'currency' => Currency::class,
            'exchange_rate' => 'decimal:8',
            'is_free_bet' => 'boolean',
            'total_odds' => 'decimal:4',
            'status' => BetStatus::class,
            'settled_at' => 'datetime',
            'payout' => 'integer',
            'payout_is_manual' => 'boolean',
            'profit' => 'integer',
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
     * @return BelongsTo<Bookmaker, $this>
     */
    public function bookmaker(): BelongsTo
    {
        return $this->belongsTo(Bookmaker::class);
    }

    /**
     * @return BelongsTo<Tipster, $this>
     */
    public function tipster(): BelongsTo
    {
        return $this->belongsTo(Tipster::class);
    }

    /**
     * The selections (legs) in the order they were entered.
     *
     * @return HasMany<Selection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Tag, $this, OwnedPivot>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->using(OwnedPivot::class);
    }

    public function isSettled(): bool
    {
        return $this->status === BetStatus::Settled;
    }
}
