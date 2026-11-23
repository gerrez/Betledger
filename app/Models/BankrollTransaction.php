<?php

namespace App\Models;

use App\Domain\Currency;
use App\Domain\TransactionType;
use Database\Factories\BankrollTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money the user deposited at, or withdrew from, one of their bookmakers. The amount is
 * in minor units of the bookmaker's currency and always positive.
 *
 * @property int $id
 * @property int $user_id
 * @property int $bookmaker_id
 * @property TransactionType $type
 * @property int $amount minor units, above zero
 * @property Currency $currency copied from the bookmaker when saved
 * @property Carbon $occurred_on a calendar date, no time
 * @property string|null $note
 * @property-read User $user
 * @property-read Bookmaker $bookmaker
 */
class BankrollTransaction extends Model
{
    /** @use HasFactory<BankrollTransactionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable. The owner is set through the user's
     * relation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bookmaker_id',
        'type',
        'amount',
        'currency',
        'occurred_on',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'currency' => Currency::class,
            'occurred_on' => 'date',
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
}
