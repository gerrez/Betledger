<?php

namespace Tests\Feature;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_demo_account_that_can_log_in_with_its_own_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Auth::attempt(['email' => DemoDataSeeder::DEMO_EMAIL, 'password' => DemoDataSeeder::PASSWORD]));

        $demo = User::query()->where('email', DemoDataSeeder::DEMO_EMAIL)->sole();
        $this->assertNotNull($demo->email_verified_at);
        $this->assertSame(4, $demo->bookmakers()->count());
        $this->assertSame(1, $demo->bookmakers()->where('is_active', false)->count());
        $this->assertSame(4, $demo->sports()->count());
        $this->assertSame(6, $demo->competitions()->count());
        $this->assertSame(9, $demo->teams()->count());
        $this->assertSame(4, $demo->markets()->count());
        $this->assertSame(2, $demo->tipsters()->count());
        $this->assertSame(3, $demo->tags()->count());
        $this->assertSame(24, $demo->bets()->count());
        $this->assertSame(2, $demo->bets()->where('status', BetStatus::Open)->count());
        $this->assertSame(2, $demo->bets()->where('type', BetType::Accumulator)->count());
        $this->assertSame(1, $demo->bets()->where('is_free_bet', true)->count());
        $this->assertSame(1, $demo->bets()->where('payout_is_manual', true)->count());
        $this->assertFalse($demo->canChangeBaseCurrency());

        $other = User::query()->where('email', DemoDataSeeder::OTHER_EMAIL)->sole();
        $this->assertSame(1, $other->sports()->count());
        $this->assertSame(1, $other->tags()->count());
        $this->assertSame(1, $other->bets()->count());
    }

    public function test_running_it_again_starts_the_demo_accounts_over(): void
    {
        $this->seed(DemoDataSeeder::class);
        $demo = User::query()->where('email', DemoDataSeeder::DEMO_EMAIL)->sole();
        $demo->tags()->where('name', 'boost')->delete();
        $demo->markets()->create(['name' => 'Added while testing']);
        $someoneElse = User::factory()->create();

        $this->seed(DemoDataSeeder::class);

        $demo = User::query()->where('email', DemoDataSeeder::DEMO_EMAIL)->sole();
        $this->assertSame(3, $demo->tags()->count());
        $this->assertFalse($demo->markets()->where('name', 'Added while testing')->exists());
        $this->assertSame(3, User::query()->count());
        $this->assertModelExists($someoneElse);
    }

    public function test_it_does_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])
            ->expectsOutputToContain('Skipped: demo accounts are never created in production.')
            ->assertSuccessful();

        $this->assertDatabaseEmpty('users');
    }
}
