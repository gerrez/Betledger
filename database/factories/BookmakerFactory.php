<?php

namespace Database\Factories;

use App\Domain\Currency;
use App\Domain\ExchangeRate;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmaker>
 */
class BookmakerFactory extends Factory
{
    /**
     * Define the model's default state: an active bookmaker in the default base currency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->company(),
            'currency' => Currency::Eur,
            'exchange_rate' => ExchangeRate::SAME_CURRENCY,
            'is_active' => true,
        ];
    }

    /**
     * A bookmaker in a foreign currency with the given rate to the base currency.
     */
    public function inCurrency(Currency $currency, string $exchangeRate): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
        ]);
    }

    /**
     * A deactivated bookmaker: hidden from the bet form, history kept.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
