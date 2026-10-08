<?php

namespace Tests\Feature\Settings;

use App\Domain\Currency;
use App\Livewire\Auth\Register;
use App\Livewire\Settings\BaseCurrency;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_start_with_euro(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertSame(Currency::Eur, User::sole()->base_currency);
    }

    public function test_the_page_shows_the_current_base_currency(): void
    {
        $this->actingAs(User::factory()->create(['base_currency' => Currency::Dkk]));

        Livewire::test(BaseCurrency::class)->assertSet('base_currency', 'DKK');
    }

    public function test_the_base_currency_can_be_changed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('base_currency', 'DKK')
            ->call('updateBaseCurrency')
            ->assertHasNoErrors()
            ->assertDispatched('base-currency-updated');

        $this->assertSame(Currency::Dkk, $user->refresh()->base_currency);
    }

    public function test_the_base_currency_must_be_supported(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('base_currency', 'JPY')
            ->call('updateBaseCurrency')
            ->assertHasErrors(['base_currency']);

        $this->assertSame(Currency::Eur, $user->refresh()->base_currency);
    }

    public function test_bookmakers_in_the_new_base_currency_get_a_rate_of_1(): void
    {
        $user = User::factory()->create();
        $danish = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $british = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create();
        $someoneElses = Bookmaker::factory()->inCurrency(Currency::Dkk, '0.1341')->create();
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('base_currency', 'DKK')
            ->call('updateBaseCurrency')
            ->assertHasNoErrors();

        $this->assertSame('1.00000000', $danish->refresh()->exchange_rate);
        $this->assertSame('1.17000000', $british->refresh()->exchange_rate);
        $this->assertSame('0.13410000', $someoneElses->refresh()->exchange_rate);
    }
}
