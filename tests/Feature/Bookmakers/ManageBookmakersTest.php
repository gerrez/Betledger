<?php

namespace Tests\Feature\Bookmakers;

use App\Domain\Currency;
use App\Livewire\Bookmakers\Index;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageBookmakersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_the_users_bookmakers_with_their_rates(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Betfair']);
        Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create(['name' => 'Danske Spil']);
        Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Old Book']);

        $this->actingAs($user)
            ->get(route('bookmakers.index'))
            ->assertOk()
            ->assertSeeInOrder(['Betfair', 'EUR · base currency', 'Danske Spil', '1 DKK = 0.1341 EUR', 'Old Book', 'Inactive']);
    }

    public function test_the_page_does_not_show_other_users_bookmakers(): void
    {
        Bookmaker::factory()->create(['name' => 'Someone Elses Book']);

        $this->actingAs(User::factory()->create())
            ->get(route('bookmakers.index'))
            ->assertOk()
            ->assertSee('No bookmakers yet')
            ->assertDontSee('Someone Elses Book');
    }

    public function test_bookmaker_names_are_escaped(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => '<script>alert("x")</script>']);

        $this->actingAs($user)
            ->get(route('bookmakers.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert("x")</script>', escape: false);
    }

    public function test_a_bookmaker_in_a_foreign_currency_can_be_added(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', '  Danske Spil  ')
            ->set('currency', 'DKK')
            ->set('exchange_rate', '0.1341')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSee('1 DKK = 0.1341 EUR');

        $bookmaker = $user->bookmakers()->sole();
        $this->assertSame('Danske Spil', $bookmaker->name);
        $this->assertSame(Currency::Dkk, $bookmaker->currency);
        $this->assertSame('0.13410000', $bookmaker->exchange_rate);
        $this->assertTrue($bookmaker->is_active);
    }

    public function test_a_bookmaker_in_the_base_currency_always_gets_a_rate_of_1(): void
    {
        $user = User::factory()->create(['base_currency' => Currency::Dkk]);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('currency', 'DKK')
            ->assertSet('exchange_rate', '1')
            ->set('name', 'Danske Spil')
            ->set('exchange_rate', '7.46')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1.00000000', $user->bookmakers()->sole()->exchange_rate);
    }

    public function test_switching_currency_resets_the_rate(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('currency', 'GBP')
            ->assertSet('exchange_rate', '')
            ->set('exchange_rate', '1.17')
            ->set('currency', 'EUR')
            ->assertSet('exchange_rate', '1');
    }

    public function test_name_and_rate_are_required(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('currency', 'GBP')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'exchange_rate' => 'required']);

        $this->assertDatabaseCount('bookmakers', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRates(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1.17'],
            'too many decimals' => ['1.123456789'],
            'not a number' => ['abc'],
        ];
    }

    #[DataProvider('invalidRates')]
    public function test_the_rate_must_be_a_positive_decimal(string $rate): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Bet365')
            ->set('currency', 'GBP')
            ->set('exchange_rate', $rate)
            ->call('save')
            ->assertHasErrors(['exchange_rate'])
            ->assertSee('Enter the rate as a number above 0, like 7.46 (up to 8 decimals).');

        $this->assertDatabaseCount('bookmakers', 0);
    }

    public function test_the_currency_must_be_supported(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Bet365')
            ->set('currency', 'JPY')
            ->set('exchange_rate', '0.0062')
            ->call('save')
            ->assertHasErrors(['currency'])
            ->assertSee('The selected currency is invalid.');

        $this->assertDatabaseCount('bookmakers', 0);
    }

    public function test_names_are_unique_per_user(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Bet365']);
        Bookmaker::factory()->create(['name' => 'Unibet']);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Bet365')
            ->call('save')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSee('You already have a bookmaker with this name.')
            ->set('name', 'Unibet')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $user->bookmakers()->count());
    }

    public function test_a_bookmaker_can_be_edited(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('edit', $bookmaker->id)
            ->assertSet('showForm', true)
            ->assertSet('name', 'Bet365')
            ->assertSet('currency', 'GBP')
            ->assertSet('exchange_rate', '1.17')
            ->set('exchange_rate', '1.1825')
            ->call('save')
            ->assertHasNoErrors();

        $bookmaker->refresh();
        $this->assertSame('Bet365', $bookmaker->name);
        $this->assertSame('1.18250000', $bookmaker->exchange_rate);
        $this->assertSame(1, $user->bookmakers()->count());
    }

    public function test_a_bookmaker_can_be_deactivated_and_reactivated(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $component = Livewire::test(Index::class)->call('deactivate', $bookmaker->id);

        $this->assertFalse($bookmaker->refresh()->is_active);

        $component->call('reactivate', $bookmaker->id);

        $this->assertTrue($bookmaker->refresh()->is_active);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bookmakerActions(): array
    {
        return [
            'edit' => ['edit'],
            'deactivate' => ['deactivate'],
            'reactivate' => ['reactivate'],
        ];
    }

    #[DataProvider('bookmakerActions')]
    public function test_users_cannot_act_on_another_users_bookmaker(string $action): void
    {
        $bookmaker = Bookmaker::factory()->inactive()->create(['name' => 'Bet365']);
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call($action, $bookmaker->id)
            ->assertNotFound()
            ->assertSet('showForm', false);

        $this->assertFalse($bookmaker->refresh()->is_active);
    }

    public function test_users_cannot_save_over_another_users_bookmaker(): void
    {
        $bookmaker = Bookmaker::factory()->create(['name' => 'Bet365']);
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('editingId', $bookmaker->id)
            ->set('name', 'Hijacked')
            ->call('save')
            ->assertNotFound();

        $this->assertSame('Bet365', $bookmaker->refresh()->name);
    }

    public function test_only_the_owner_may_update_a_bookmaker(): void
    {
        $bookmaker = Bookmaker::factory()->create();

        $this->assertTrue(Gate::forUser($bookmaker->user)->allows('update', $bookmaker));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('update', $bookmaker));
    }
}
