<?php

namespace Database\Factories;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    /**
     * Define the model's default state: an Android phone that registered just now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => fake()->unique()->regexify('[A-Za-z0-9_-]{22}:APA91b[A-Za-z0-9_-]{134}'),
            'platform' => PushDevice::PLATFORM_ANDROID,
            'name' => fake()->randomElement(['Google Pixel 8', 'Samsung SM-S921B', 'OnePlus CPH2581']),
            'last_seen_at' => now(),
        ];
    }
}
