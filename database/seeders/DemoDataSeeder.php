<?php

namespace Database\Seeders;

use App\Domain\Currency;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo accounts for trying the app locally: `php artisan db:seed`.
 *
 * Every run starts the demo accounts over (their old data is deleted), so changes made
 * while testing are easy to undo. Never runs in production: the passwords are public.
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@example.com';

    public const OTHER_EMAIL = 'other@example.com';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipped: demo accounts are never created in production.');

            return;
        }

        DB::transaction(function (): void {
            User::query()->whereIn('email', [self::DEMO_EMAIL, self::OTHER_EMAIL])->delete();

            $this->seedDemoUser($this->createUser('Demo', self::DEMO_EMAIL));
            $this->seedOtherUser($this->createUser('Other User', self::OTHER_EMAIL));
        });

        $this->command->info('Log in as '.self::DEMO_EMAIL.' (or '.self::OTHER_EMAIL.') with password "'.self::PASSWORD.'".');
    }

    private function createUser(string $name, string $email): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'base_currency' => Currency::Eur,
        ])->save();

        return $user;
    }

    /**
     * The account to test with: bookmakers in several currencies (one inactive) and
     * every list, including a sport nothing uses yet so it can be deleted.
     */
    private function seedDemoUser(User $user): void
    {
        $user->bookmakers()->createMany([
            ['name' => 'Unibet', 'currency' => Currency::Eur, 'exchange_rate' => '1'],
            ['name' => 'Bet365', 'currency' => Currency::Gbp, 'exchange_rate' => '1.17'],
            ['name' => 'Danske Spil', 'currency' => Currency::Dkk, 'exchange_rate' => '0.1341'],
            ['name' => 'Old Bookie', 'currency' => Currency::Eur, 'exchange_rate' => '1', 'is_active' => false],
        ]);

        $football = $user->sports()->create(['name' => 'Football']);
        $tennis = $user->sports()->create(['name' => 'Tennis']);
        $basketball = $user->sports()->create(['name' => 'Basketball']);
        $user->sports()->create(['name' => 'Ice hockey']);

        foreach (['Premier League', 'La Liga', 'Champions League'] as $name) {
            $user->competitions()->create(['sport_id' => $football->id, 'name' => $name]);
        }
        foreach (['Wimbledon', 'US Open'] as $name) {
            $user->competitions()->create(['sport_id' => $tennis->id, 'name' => $name]);
        }
        $user->competitions()->create(['sport_id' => $basketball->id, 'name' => 'NBA']);

        foreach (['Arsenal', 'Chelsea', 'Liverpool', 'Real Madrid', 'FC Barcelona'] as $name) {
            $user->teams()->create(['sport_id' => $football->id, 'name' => $name]);
        }
        foreach (['Carlos Alcaraz', 'Jannik Sinner'] as $name) {
            $user->teams()->create(['sport_id' => $tennis->id, 'name' => $name]);
        }
        foreach (['Boston Celtics', 'Los Angeles Lakers'] as $name) {
            $user->teams()->create(['sport_id' => $basketball->id, 'name' => $name]);
        }

        foreach (['1X2', 'Asian handicap', 'Over/Under', 'Both teams to score'] as $name) {
            $user->markets()->create(['name' => $name]);
        }
        foreach (['Own model', 'Value hunting'] as $name) {
            $user->tipsters()->create(['name' => $name]);
        }
        foreach (['value', 'live', 'boost'] as $name) {
            $user->tags()->create(['name' => $name]);
        }
    }

    /**
     * A second account whose data must never show up for the demo user.
     */
    private function seedOtherUser(User $user): void
    {
        $user->bookmakers()->create(['name' => 'Other Users Bookie', 'currency' => Currency::Eur, 'exchange_rate' => '1']);

        $sport = $user->sports()->create(['name' => 'Other Users Sport']);
        $user->competitions()->create(['sport_id' => $sport->id, 'name' => 'Other Users Competition']);
        $user->teams()->create(['sport_id' => $sport->id, 'name' => 'Other Users Team']);
        $user->markets()->create(['name' => 'Other Users Market']);
        $user->tipsters()->create(['name' => 'Other Users Tipster']);
        $user->tags()->create(['name' => 'Other Users Tag']);
    }
}
