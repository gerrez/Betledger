<?php

namespace App\Models;

use App\Domain\Currency;
use App\Domain\Decimal;
use Database\Factories\BookmakerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Currency $currency
 * @property string $exchange_rate 1 unit of the currency = this many units of the user's base currency
 * @property bool $is_active
 */
class Bookmaker extends Model
{
    /** @use HasFactory<BookmakerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'currency',
        'exchange_rate',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'exchange_rate' => 'decimal:8',
            'is_active' => 'boolean',
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
     * The rate without the column's trailing zeros: "0.13410000" → "0.1341".
     */
    public function formattedExchangeRate(): string
    {
        return Decimal::normalize($this->exchange_rate);
    }
}
