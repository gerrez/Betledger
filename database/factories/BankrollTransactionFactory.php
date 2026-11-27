<?php

namespace Database\Factories;

use App\Domain\TransactionType;
use App\Models\BankrollTransaction;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankrollTransaction>
 */
class BankrollTransactionFactory extends Factory
{
    /**
     * Define the model's default state: a deposit at a bookmaker of the same user, in
     * the bookmaker's currency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bookmaker_id' => fn (array $attributes) => Bookmaker::factory()->create(['user_id' => $attributes['user_id']])->id,
            'type' => TransactionType::Deposit,
            'amount' => fake()->numberBetween(1, 40) * 2500,
            'currency' => fn (array $attributes) => Bookmaker::findOrFail($attributes['bookmaker_id'])->currency,
            'occurred_on' => fake()->dateTimeBetween(today()->subDays(60), today())->format('Y-m-d'),
            'note' => null,
        ];
    }

    /**
     * A transaction at the given bookmaker, owned by its user, in its currency.
     */
    public function forBookmaker(Bookmaker $bookmaker): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $bookmaker->user_id,
            'bookmaker_id' => $bookmaker->id,
            'currency' => $bookmaker->currency,
        ]);
    }

    public function deposit(int $amount = 10000): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TransactionType::Deposit,
            'amount' => $amount,
        ]);
    }

    public function withdrawal(int $amount = 10000): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TransactionType::Withdrawal,
            'amount' => $amount,
        ]);
    }
}
