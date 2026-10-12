<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * Define the model's default state: a competition under a sport of the same user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sport_id' => fn (array $attributes) => Sport::factory()->create(['user_id' => $attributes['user_id']])->id,
            'name' => ucwords(fake()->unique()->words(2, true)).' League',
        ];
    }

    /**
     * A competition under the given sport, owned by the sport's user.
     */
    public function forSport(Sport $sport): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $sport->user_id,
            'sport_id' => $sport->id,
        ]);
    }
}
