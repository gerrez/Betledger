<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\ApiTokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('settings.api-tokens'))->assertRedirect(route('login'));
    }

    public function test_the_page_is_linked_from_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.profile'))->assertOk()->assertSee(route('settings.api-tokens'));
    }

    public function test_a_created_token_is_shown_once_and_authenticates_the_api(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(ApiTokens::class)
            ->set('name', 'Betting spreadsheet')
            ->call('createToken')
            ->assertHasNoErrors()
            ->assertSet('name', '')
            ->assertSee('Copy your new token');

        $plainTextToken = $component->get('plainTextToken');
        $this->assertIsString($plainTextToken);
        $this->assertSame(['Betting spreadsheet'], $user->tokens()->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->withToken($plainTextToken)->postJson(route('api.v1.bets.store'), [])->assertUnprocessable();

        Livewire::test(ApiTokens::class)
            ->assertSee('Betting spreadsheet')
            ->assertDontSee('Copy your new token')
            ->assertDontSee($plainTextToken);
    }

    public function test_a_token_needs_a_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->call('createToken')
            ->assertHasErrors(['name' => 'Name the token, e.g. after the script or app that uses it.']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_revoking_a_token_stops_it_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Odds feed');
        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->call('revokeToken', $token->accessToken->id)
            ->assertDontSee('Odds feed')
            ->assertSee('You have no API tokens.');

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->postJson(route('api.v1.bets.store'), [])->assertUnauthorized();
    }

    public function test_another_users_tokens_are_neither_listed_nor_revocable(): void
    {
        $user = User::factory()->create();
        $otherToken = User::factory()->create()->createToken('Other Users Script');
        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->assertDontSee('Other Users Script')
            ->call('revokeToken', $otherToken->accessToken->id)
            ->assertNotFound();

        $this->assertNotNull($otherToken->accessToken->fresh());
    }

    public function test_the_creation_date_is_the_users_local_date(): void
    {
        $this->travelTo('2026-11-22 23:30:00'); // 23 November in Copenhagen
        $user = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $user->createToken('Odds feed');
        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->assertSee('Created 23 Nov 2026 · Never used')
            ->assertDontSee('22 Nov 2026');
    }

    public function test_token_names_are_escaped(): void
    {
        $user = User::factory()->create();
        $user->createToken('<script>alert(1)</script>');
        $this->actingAs($user);

        $this->get(route('settings.api-tokens'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
