<?php

namespace Tests\Feature\Settings;

use App\Domain\BetStatus;
use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Settings\Import;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../Fixtures/sportmarket/orders.csv';

    /**
     * The fixture's 17 orders: 16 settled ones and one whose every fill failed.
     */
    private const IMPORTABLE_ORDERS = 16;

    public function test_the_page_is_linked_from_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.profile'))->assertOk()->assertSee(route('settings.import'));
    }

    public function test_the_bookmaker_named_sportmarket_is_preselected(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Bet365']);
        $sportmarket = Bookmaker::factory()->for($user)->create(['name' => 'sportmarket']);

        $this->actingAs($user);

        Livewire::test(Import::class)->assertSet('bookmaker', (string) $sportmarket->id);
    }

    public function test_a_user_without_active_bookmakers_is_sent_to_the_bookmakers_page(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->inactive()->create();

        $this->actingAs($user);

        Livewire::test(Import::class)
            ->assertSet('bookmaker', '')
            ->assertSee('Add the bookmaker to import the bets to')
            ->assertSee(route('bookmakers.index'));
    }

    public function test_the_preview_shows_every_order_and_saves_nothing(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create(['name' => 'Sportmarket']);

        $this->preview($user, $sportmarket)
            ->assertHasNoErrors()
            ->assertSet('previewing', true)
            ->assertSee('17 orders: 16 to import, 1 skipped, 0 errors.')
            ->assertSee('Sporting CP v LASK Linz')
            ->assertSee('AGAINST Under 3.5/4 (Asian) @ 1.441')
            ->assertSee('Order 2379759923')
            ->assertSee('Every fill failed, so no bet was placed.')
            ->assertSee('Import 16 bets');

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_confirming_creates_the_settled_bets(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create(['name' => 'Sportmarket']);

        $this->preview($user, $sportmarket)
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect(route('bets.index', ['status' => 'settled']));

        $this->assertSame('Imported 16 bets.', session('status'));
        $this->assertSame(self::IMPORTABLE_ORDERS, $user->bets()->count());
        $this->assertSame(self::IMPORTABLE_ORDERS, $sportmarket->bets()->where('status', BetStatus::Settled)->count());

        $bet = $user->bets()->where('external_ref', 'sportmarket:2384017276')->sole();
        $selection = $bet->selections()->sole();

        $this->assertSame(Currency::Eur, $bet->currency);
        $this->assertSame(14857, $bet->stake);
        $this->assertSame(21409, $bet->payout);
        $this->assertSame(6552, $bet->profit);
        $this->assertFalse($bet->payout_is_manual);
        $this->assertSame('2026-10-21 20:26:44', $bet->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 23:59:00', $bet->settled_at?->format('Y-m-d H:i:s'));
        $this->assertSame(['In-play'], $bet->tags->pluck('name')->all());
        $this->assertSame('Sportmarket order 2384017276 · 1 fill: fair', $bet->notes);
        $this->assertSame(SelectionResult::Won, $selection->result);
        $this->assertSame('1.441', $selection->odds);
        $this->assertSame('Football', $selection->sport->name);
        $this->assertSame('UEFA Champions League', $selection->competition?->name);
        $this->assertSame('Asian total goals', $selection->market?->name);
        $this->assertSame(['LASK Linz', 'Sporting CP'], $selection->teams->pluck('name')->sort()->values()->all());
        $this->assertSame('AGAINST Under 3.5/4 (Asian)', $selection->pick);
    }

    public function test_a_payout_that_differs_from_the_calculated_one_is_kept_as_manual(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create();

        $this->preview($user, $sportmarket)->call('import');

        $bet = $user->bets()->where('external_ref', 'sportmarket:2355359715')->sole();

        $this->assertTrue($bet->payout_is_manual);
        $this->assertSame(22450, $bet->payout);
        $this->assertSame(SelectionResult::Won, $bet->selections()->sole()->result);
    }

    public function test_bets_in_another_currency_than_the_base_get_the_bookmakers_rate(): void
    {
        $user = User::factory()->create(['base_currency' => Currency::Dkk]);
        $sportmarket = Bookmaker::factory()->for($user)->inCurrency(Currency::Eur, '7.46')->create();

        $this->preview($user, $sportmarket)->call('import');

        $this->assertSame(['7.46000000'], $user->bets()->distinct()->pluck('exchange_rate')->all());
    }

    public function test_importing_the_same_file_again_creates_nothing(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create();

        $this->preview($user, $sportmarket)->call('import');

        $this->preview($user, $sportmarket)
            ->assertSee('17 orders: 0 to import, 17 skipped, 0 errors.')
            ->assertSee('Imported before.')
            ->assertDontSee('Import 0 bets')
            ->call('import')
            ->assertHasErrors(['file'])
            ->assertNoRedirect();

        $this->assertSame(self::IMPORTABLE_ORDERS, $user->bets()->count());
    }

    public function test_a_file_in_another_currency_is_rejected(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();

        $this->preview($user, $sportmarket)
            ->assertSee('17 orders: 0 to import, 1 skipped, 16 errors.')
            ->assertSee('the order is in EUR, but the bookmaker is in DKK.')
            ->call('import')
            ->assertHasErrors(['file']);

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_file_that_is_not_an_orders_export_is_refused(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create();

        $this->preview($user, $sportmarket, "Date,Amount\n2026-10-21,10.00\n")
            ->assertHasErrors(['file'])
            ->assertSet('previewing', false);
    }

    public function test_the_file_must_be_a_csv(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create();

        $this->actingAs($user);

        Livewire::test(Import::class)
            ->set('bookmaker', (string) $sportmarket->id)
            ->set('file', UploadedFile::fake()->create('orders.pdf', 10))
            ->call('preview')
            ->assertHasErrors(['file' => 'extensions'])
            ->assertSet('previewing', false);
    }

    public function test_changing_the_bookmaker_leaves_the_preview(): void
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create();
        $other = Bookmaker::factory()->for($user)->create();

        $this->preview($user, $sportmarket)
            ->set('bookmaker', (string) $other->id)
            ->assertSet('previewing', false);
    }

    public function test_bets_cannot_be_imported_to_another_users_bookmaker(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $othersBookmaker = Bookmaker::factory()->create();

        $this->preview($user, $othersBookmaker)
            ->assertHasErrors(['bookmaker' => 'exists'])
            ->call('import')
            ->assertHasErrors(['bookmaker' => 'exists']);

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_bets_cannot_be_imported_to_an_inactive_bookmaker(): void
    {
        $user = User::factory()->create();
        $inactive = Bookmaker::factory()->for($user)->inactive()->create();

        $this->preview($user, $inactive)->assertHasErrors(['bookmaker' => 'exists']);
    }

    public function test_list_entries_and_imported_orders_are_per_user(): void
    {
        $other = User::factory()->create();
        $othersFootball = Sport::factory()->for($other)->create(['name' => 'Football']);
        Bet::factory()->for($other)->forBookmaker(Bookmaker::factory()->for($other)->create())
            ->create(['external_ref' => 'sportmarket:2384017276']);

        $othersSports = $other->sports()->pluck('id')->all();
        $othersTeams = $other->teams()->pluck('id')->all();

        $user = User::factory()->create();
        $ownFootball = Sport::factory()->for($user)->create(['name' => 'football']);
        $sportmarket = Bookmaker::factory()->for($user)->create();

        $this->preview($user, $sportmarket)
            ->assertSee('17 orders: 16 to import, 1 skipped, 0 errors.')
            ->call('import');

        $this->assertSame(self::IMPORTABLE_ORDERS, $user->bets()->count());
        $this->assertTrue($user->bets()->where('external_ref', 'sportmarket:2384017276')->exists());
        $this->assertSame([$ownFootball->id], $user->sports()->pluck('id')->all());
        $this->assertContains($othersFootball->id, $othersSports);
        $this->assertSame($othersSports, $other->sports()->pluck('id')->all());
        $this->assertSame($othersTeams, $other->teams()->pluck('id')->all());
        $this->assertSame(1, $other->bets()->count());
    }

    /**
     * Upload a file (the fixture unless given) for the bookmaker and preview it.
     */
    private function preview(User $user, Bookmaker $bookmaker, ?string $contents = null): Testable
    {
        $this->actingAs($user);

        return Livewire::test(Import::class)
            ->set('bookmaker', (string) $bookmaker->id)
            ->set('file', UploadedFile::fake()->createWithContent('orders.csv', $contents ?? (string) file_get_contents(self::FIXTURE)))
            ->call('preview');
    }
}
