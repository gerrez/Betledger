<?php

namespace Database\Factories;

use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Sport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Selections need a bet: create them through BetFactory (its single() and accumulator()
 * states), or with ->for($bet) to add a leg to an existing bet.
 *
 * @extends Factory<Selection>
 */
class SelectionFactory extends Factory
{
    /**
     * Define the model's default state: a pending selection after the bet's other
     * selections, on a sport of the bet's owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $oddsInHundredths = fake()->numberBetween(105, 450);

        return [
            'bet_id' => null, // listed first so that for($bet) and has() fill it in before the closures below run
            'user_id' => fn (array $attributes) => Bet::findOrFail($attributes['bet_id'])->user_id,
            'position' => fn (array $attributes) => Selection::query()->where('bet_id', $attributes['bet_id'])->max('position') + 1,
            'sport_id' => fn (array $attributes) => Sport::factory()->create(['user_id' => $attributes['user_id']])->id,
            'competition_id' => null,
            'market_id' => null,
            'event_name' => fake()->city().' v '.fake()->city(),
            'event_starts_at' => fake()->dateTimeBetween('-30 days', '+7 days'),
            'pick' => fake()->randomElement(['Home', 'Draw', 'Away', 'Over 2.5', 'Under 2.5']),
            'odds' => sprintf('%d.%02d', intdiv($oddsInHundredths, 100), $oddsInHundredths % 100),
            'closing_odds' => null,
            'result' => null,
        ];
    }

    /**
     * A selection with the given result.
     */
    public function withResult(SelectionResult $result): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => $result,
        ]);
    }
}
