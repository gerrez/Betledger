<?php

namespace Tests\Feature\Bets;

use App\Domain\Bankroll\BankrollQuery;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Livewire\Bets\Index as BetsIndex;
use App\Livewire\Bets\Show;
use App\Livewire\Lists\Index as ListsIndex;
use App\Livewire\Settings\DeletedBets;
use App\Livewire\Settings\ResetAccountForm;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deleting a bet moves it to Settings → Deleted bets (soft delete, 6.11): it drops out of
 * every page and total until it is restored, deleted for good, or pruned after 30 days.
 */
class DeletedBetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create();
    }

    public function test_a_deleted_bet_is_absent_from_every_page(): void
    {
        $this->bet('Kept Town v Kept City')->delete();
        $this->bet('Gone Town v Gone City', settled: true)->delete();
        $this->bet('Open Town v Open City');
        $this->actingAs($this->user);

        foreach (['open', 'settled', 'all'] as $tab) {
            $this->get(route('bets.index', ['status' => $tab]))->assertOk()->assertDontSee(['Kept Town', 'Gone Town']);
        }

        $this->get(route('bets.index', ['status' => 'all', 'search' => 'Gone']))->assertOk()->assertDontSee('Gone Town');
        $this->get(route('dashboard'))->assertOk()->assertSee('Open Town')->assertDontSee(['Kept Town', 'Gone Town']);
        $this->get(route('statistics'))->assertOk();
        $csv = (string) $this->get(route('settings.export.download'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Open Town', $csv);
        $this->assertStringNotContainsString('Gone Town', $csv);
        $this->assertStringNotContainsString('Kept Town', $csv);
    }

    public function test_a_deleted_bet_cannot_be_opened_edited_or_settled(): void
    {
        $bet = $this->bet('Gone Town v Gone City');
        $bet->delete();
        $this->actingAs($this->user);

        $this->get(route('bets.show', $bet))->assertNotFound();
        $this->get(route('bets.edit', $bet))->assertNotFound();

        Livewire::test(BetsIndex::class)
            ->call('settle', $bet->selections()->firstOrFail()->id, SelectionResult::Won->value)
            ->assertNotFound();

        $this->assertNull($bet->selections()->firstOrFail()->result);
    }

    public function test_a_bet_deleted_in_another_tab_is_not_changed_from_its_open_page(): void
    {
        $bet = $this->bet('Gone Town v Gone City');
        $this->actingAs($this->user);

        $page = Livewire::test(Show::class, ['bet' => $bet]);
        $bet->delete();

        $page->call('setResult', $bet->selections()->firstOrFail()->id, SelectionResult::Won->value)->assertNotFound();

        $this->assertNull($bet->selections()->firstOrFail()->result);
    }

    public function test_a_deleted_bet_counts_in_no_total(): void
    {
        $this->bet('Kept Town v Kept City', settled: true);
        $this->bet('Open Town v Open City');
        $deletedSettled = $this->bet('Gone Town v Gone City', settled: true);
        $deletedOpen = $this->bet('Gone Village v Gone City');
        $deletedSettled->delete();
        $deletedOpen->delete();

        $withDeleted = $this->totals();

        $deletedSettled->forceDelete();
        $deletedOpen->forceDelete();

        $this->assertEquals($this->totals(), $withDeleted);
        $this->assertSame(1, $withDeleted['openStake']->bets);
        $this->assertCount(2, $withDeleted['bets']);
    }

    public function test_the_page_lists_only_the_users_deleted_bets_newest_deletion_first(): void
    {
        $this->travelTo(now()->subDays(3));
        $this->bet('Older Town v Older City')->delete();
        $this->travelBack();
        $this->bet('Newer Town v Newer City')->delete();
        $this->bet('Kept Town v Kept City');
        $theirs = Bet::factory()->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Their Town v Their City']), 'selections')->create();
        $theirs->delete();
        $this->actingAs($this->user);

        $this->get(route('settings.deleted-bets'))
            ->assertOk()
            ->assertSeeInOrder(['2 deleted bets.', 'Newer Town v Newer City', 'Older Town v Older City'])
            ->assertSee('can be restored until')
            ->assertDontSee(['Kept Town', 'Their Town']);
    }

    public function test_the_page_explains_when_nothing_is_deleted(): void
    {
        $this->actingAs($this->user);

        $this->get(route('settings.deleted-bets'))
            ->assertOk()
            ->assertSee('You have no deleted bets.')
            ->assertDontSee('Delete all for good');
    }

    public function test_a_deleted_bet_is_restored_with_its_selections_results_and_tags(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->accumulator()->withResults(SelectionResult::Won, SelectionResult::Lost, null)->create();
        $bet->tags()->attach(Tag::factory()->for($this->user)->create());
        $profit = $bet->profit;
        $bet->delete();
        $this->actingAs($this->user);

        Livewire::test(DeletedBets::class)
            ->call('restore', $bet->id)
            ->assertSee(['Bet restored.', 'You have no deleted bets.'])
            ->assertSeeHtml('href="'.route('bets.show', $bet).'"');

        $this->assertNotSoftDeleted($bet);
        $restored = $this->user->bets()->findOrFail($bet->id);
        $this->assertSame([SelectionResult::Won, SelectionResult::Lost, null], $restored->selections->pluck('result')->all());
        $this->assertSame(1, $restored->tags()->count());
        $this->assertSame($profit, $restored->profit);
        $this->get(route('bets.show', $bet))->assertOk();
    }

    public function test_a_deleted_bet_is_deleted_for_good_with_its_selections_and_links(): void
    {
        $bet = $this->bet('Gone Town v Gone City');
        $bet->tags()->attach(Tag::factory()->for($this->user)->create());
        $bet->selections()->firstOrFail()->teams()->attach(Team::factory()->for($this->user)->create());
        $kept = $this->bet('Other Town v Other City');
        $kept->delete();
        $bet->delete();
        $this->actingAs($this->user);

        Livewire::test(DeletedBets::class)
            ->assertSeeHtml(['data-confirm-title="Delete “Gone Town v Gone City” for good?"', 'data-confirm-danger'])
            ->call('forceDelete', $bet->id)
            ->assertDontSee('Gone Town')
            ->assertSee('Other Town');

        $this->assertModelMissing($bet);
        $this->assertSoftDeleted($kept);
        $this->assertSame(0, Selection::query()->where('bet_id', $bet->id)->count());
        $this->assertSame(0, DB::table('bet_tag')->where('bet_id', $bet->id)->count());
        $this->assertDatabaseCount('selection_team', 0);
    }

    public function test_a_bet_that_is_not_deleted_cannot_be_deleted_for_good_or_restored_here(): void
    {
        $bet = $this->bet('Kept Town v Kept City');
        $this->actingAs($this->user);

        Livewire::test(DeletedBets::class)->call('forceDelete', $bet->id)->assertNotFound();
        Livewire::test(DeletedBets::class)->call('restore', $bet->id)->assertNotFound();

        $this->assertNotSoftDeleted($bet);
    }

    public function test_all_deleted_bets_are_deleted_for_good_at_once(): void
    {
        Bet::factory()->count(DeletedBets::PER_PAGE + 2)->forBookmaker($this->bookmaker)->create()->each->delete();
        $kept = $this->bet('Kept Town v Kept City');
        $theirs = Bet::factory()->create();
        $theirs->delete();
        $this->actingAs($this->user);

        Livewire::test(DeletedBets::class)
            ->assertSee('27 deleted bets.')
            ->assertSeeHtml('data-confirm-title="Delete 27 bets for good?"')
            ->call('forceDeleteAll')
            ->assertSee(['27 bets deleted for good.', 'You have no deleted bets.']);

        $this->assertSame(0, $this->user->bets()->withTrashed()->whereNotNull('deleted_at')->count());
        $this->assertNotSoftDeleted($kept);
        $this->assertSoftDeleted($theirs);
    }

    public function test_users_cannot_restore_or_delete_for_good_another_users_deleted_bet(): void
    {
        $theirs = Bet::factory()->create();
        $theirs->delete();
        $this->actingAs($this->user);

        Livewire::test(DeletedBets::class)->call('restore', $theirs->id)->assertNotFound();
        Livewire::test(DeletedBets::class)->call('forceDelete', $theirs->id)->assertNotFound();

        $this->assertSoftDeleted($theirs);
    }

    public function test_bets_deleted_more_than_30_days_ago_are_pruned(): void
    {
        $this->freezeTime();
        $old = $this->bet('Old Town v Old City');
        $recent = $this->bet('Recent Town v Recent City');
        $kept = $this->bet('Kept Town v Kept City');
        $theirs = Bet::factory()->create();

        $this->travel(-30)->days();
        $old->delete();
        $theirs->delete();
        $this->travel(1)->minutes();
        $recent->delete();
        $this->travelBack();
        $this->freezeTime();

        $this->artisan('model:prune', ['--model' => [Bet::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($theirs);
        $this->assertSame(0, Selection::query()->where('bet_id', $old->id)->count());
        $this->assertSoftDeleted($recent);
        $this->assertNotSoftDeleted($kept);
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'Bet'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->firstOrFail()->expression);
    }

    /**
     * The bet's sport has no competitions or teams of its own (they are another sport's),
     * so only the deleted bet uses each entry.
     */
    #[DataProvider('lists')]
    public function test_a_deleted_bet_does_not_keep_a_list_entry_in_use_and_goes_with_it(string $list): void
    {
        $football = Sport::factory()->for($this->user)->create();
        $tennis = Sport::factory()->for($this->user)->create();
        $entries = [
            'sports' => $football,
            'competitions' => Competition::factory()->forSport($tennis)->create(),
            'teams' => Team::factory()->forSport($tennis)->create(),
            'markets' => Market::factory()->for($this->user)->create(),
            'tipsters' => Tipster::factory()->for($this->user)->create(),
            'tags' => Tag::factory()->for($this->user)->create(),
        ];
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->create(['tipster_id' => $entries['tipsters']->id]);
        $bet->tags()->attach($entries['tags']);
        $selection = $bet->selections()->firstOrFail();
        $selection->update(['sport_id' => $football->id, 'competition_id' => $entries['competitions']->id, 'market_id' => $entries['markets']->id]);
        $selection->teams()->attach($entries['teams']);
        $kept = $this->bet('Kept Town v Kept City');
        $kept->delete();
        $bet->delete();
        $this->actingAs($this->user);

        Livewire::test(ListsIndex::class, ['list' => $list])
            ->assertSeeHtml('data-confirm="A recently deleted bet uses it and is deleted for good too. This can’t be undone."')
            ->call('delete', $entries[$list]->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($entries[$list]);
        $this->assertModelMissing($bet);
        $this->assertSoftDeleted($kept);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lists(): array
    {
        return array_combine(
            ['sports', 'competitions', 'teams', 'markets', 'tipsters', 'tags'],
            [['sports'], ['competitions'], ['teams'], ['markets'], ['tipsters'], ['tags']],
        );
    }

    public function test_deleting_an_entry_leaves_deleted_bets_that_do_not_use_it(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $other = $this->bet('Other Town v Other City');
        $other->delete();
        $this->actingAs($this->user);

        Livewire::test(ListsIndex::class, ['list' => 'tags'])
            ->assertSeeHtml('data-confirm="This can’t be undone."')
            ->call('delete', $tag->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($tag);
        $this->assertSoftDeleted($other);
    }

    public function test_a_deleted_bet_still_locks_the_currencies(): void
    {
        $this->bet('Gone Town v Gone City')->delete();

        $this->assertFalse($this->bookmaker->canChangeCurrency());
        $this->assertFalse($this->user->canChangeBaseCurrency());

        $this->user->bets()->onlyTrashed()->forceDelete();

        $this->assertTrue($this->bookmaker->canChangeCurrency());
        $this->assertTrue($this->user->canChangeBaseCurrency());
    }

    public function test_resetting_the_account_deletes_the_deleted_bets_for_good_too(): void
    {
        $deleted = $this->bet('Gone Town v Gone City');
        $deleted->delete();
        $this->bet('Kept Town v Kept City');
        $theirs = Bet::factory()->create();
        $theirs->delete();
        $this->actingAs($this->user);

        Livewire::test(ResetAccountForm::class)
            ->assertSee(['All 2 of your bets will be permanently deleted,', 'This includes 1 recently deleted bet.'])
            ->set('password', 'password')
            ->call('resetAccount')
            ->assertHasNoErrors();

        $this->assertSame(0, $this->user->bets()->withTrashed()->count());
        $this->assertSoftDeleted($theirs);
    }

    /**
     * A single at the test bookmaker with this event name, open or settled as won.
     */
    private function bet(string $eventName, bool $settled = false): Bet
    {
        $factory = Bet::factory()->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => $eventName]), 'selections');

        return ($settled ? $factory->settled() : $factory)->create();
    }

    /**
     * Every total the pages show, worked out from what the queries load.
     *
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $bets = app(StatisticsQuery::class)->bets($this->user, new StatisticsFilter);

        return [
            'bets' => $bets,
            'statistics' => app(StatisticsCalculator::class)->totals($bets),
            'openStake' => app(StatisticsQuery::class)->openStake($this->user),
            'bankroll' => app(BankrollQuery::class)->bankroll($this->user),
        ];
    }
}
