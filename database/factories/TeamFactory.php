<?php

namespace Database\Factories;

use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state: a team under a sport of the same user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sport_id' => fn (array $attributes) => Sport::factory()->create(['user_id' => $attributes['user_id']])->id,
            'name' => fake()->unique()->city(),
        ];
    }

    /**
     * A team under the given sport, owned by the sport's user.
     */
    public function forSport(Sport $sport): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $sport->user_id,
            'sport_id' => $sport->id,
        ]);
    }
}
