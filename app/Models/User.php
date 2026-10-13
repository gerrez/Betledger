<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Currency;
use App\Domain\ExchangeRate;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property Currency $base_currency
 */
class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'base_currency' => 'EUR',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'base_currency' => Currency::class,
        ];
    }

    /**
     * @return HasMany<Bookmaker, $this>
     */
    public function bookmakers(): HasMany
    {
        return $this->hasMany(Bookmaker::class);
    }

    /**
     * @return HasMany<Bet, $this>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /**
     * @return HasMany<Selection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }

    /**
     * @return HasMany<Sport, $this>
     */
    public function sports(): HasMany
    {
        return $this->hasMany(Sport::class);
    }

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
     * @return HasMany<Market, $this>
     */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    /**
     * @return HasMany<Tipster, $this>
     */
    public function tipsters(): HasMany
    {
        return $this->hasMany(Tipster::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * The base currency can only change while the user has no bets: each bet's exchange
     * rate converts to the base currency it was saved with.
     */
    public function canChangeBaseCurrency(): bool
    {
        return $this->bets()->doesntExist();
    }

    /**
     * Switch to another base currency. Bookmakers in the new base currency get the
     * fixed rate of 1; other rates are left for the user to review.
     *
     * @throws LogicException when the user has bets
     */
    public function changeBaseCurrency(Currency $currency): void
    {
        if ($currency === $this->base_currency) {
            return;
        }

        if (! $this->canChangeBaseCurrency()) {
            throw new LogicException('The base currency cannot change once the user has bets.');
        }

        DB::transaction(function () use ($currency): void {
            $this->base_currency = $currency;
            $this->save();

            $this->bookmakers()
                ->where('currency', $currency)
                ->update(['exchange_rate' => ExchangeRate::SAME_CURRENCY]);
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
