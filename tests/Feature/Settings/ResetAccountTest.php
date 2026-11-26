<?php

namespace Tests\Feature\Settings;

use App\Domain\Settlement\SelectionResult;
use App\Livewire\Settings\ResetAccountForm;
use App\Models\BankrollTransaction;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ResetAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_the_reset_warning(): void
    {
        $user = User::factory()->create();
        Bet::factory()->for($user)->count(3)->create();

        $this->actingAs($user)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Reset Account')
            ->assertSee('This cannot be undone')
            ->assertSee('All 3 of your bets will be permanently deleted,');
    }

    public function test_reset_deletes_all_bets_and_keeps_everything_else(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $deposit = BankrollTransaction::factory()->forBookmaker($bookmaker)->deposit()->create();

        $single = Bet::factory()->forBookmaker($bookmaker)->single()->settled(SelectionResult::Won)->create();
        $single->tags()->attach($tag->id);
        Bet::factory()->forBookmaker($bookmaker)->accumulator()->create();

        $this->actingAs($user);

        Livewire::test(ResetAccountForm::class)
            ->set('password', 'password')
            ->call('resetAccount')
            ->assertHasNoErrors()
            ->assertSet('password', '')
            ->assertDispatched('account-reset');

        $this->assertSame(0, $user->bets()->count());
        $this->assertDatabaseMissing('selections', ['user_id' => $user->id]);
        $this->assertSame(0, DB::table('bet_tag')->where('user_id', $user->id)->count());

        $this->assertNotNull($user->fresh());
        $this->assertModelExists($bookmaker);
        $this->assertModelExists($tag);
        $this->assertModelExists($deposit);
    }

    public function test_wrong_password_keeps_the_bets(): void
    {
        $user = User::factory()->create();
        Bet::factory()->for($user)->count(2)->create();

        $this->actingAs($user);

        Livewire::test(ResetAccountForm::class)
            ->set('password', 'wrong-password')
            ->call('resetAccount')
            ->assertHasErrors(['password']);

        $this->assertSame(2, $user->bets()->count());
    }

    public function test_reset_does_not_touch_another_users_bets(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Bet::factory()->for($userA)->count(2)->create();
        Bet::factory()->for($userB)->create();

        $this->actingAs($userB);

        Livewire::test(ResetAccountForm::class)
            ->set('password', 'password')
            ->call('resetAccount')
            ->assertHasNoErrors();

        $this->assertSame(0, $userB->bets()->count());
        $this->assertSame(2, $userA->bets()->count());
        $this->assertSame(2, DB::table('selections')->where('user_id', $userA->id)->count());
    }
}
