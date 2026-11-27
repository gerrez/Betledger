<?php

namespace Tests\Feature\Bets;

use App\Domain\Bets\BulkBetActions;
use App\Livewire\Bets\Index;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27 15:00:00');
        $this->user = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create();
    }

    public function test_select_mode_shows_checkboxes_instead_of_the_result_buttons(): void
    {
        $this->bet('Arsenal v Chelsea');
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee('How did it go?')
            ->assertDontSee('Select Arsenal v Chelsea')
            ->call('startSelecting')
            ->assertSee(['Select Arsenal v Chelsea', 'Tick the bets to change', 'Select all 1', 'Done'])
            ->assertDontSee('How did it go?')
            ->call('stopSelecting')
            ->assertSet('selecting', false)
            ->assertSee('How did it go?');
    }

    public function test_the_ticked_bets_are_moved_to_deleted_bets(): void
    {
        $arsenal = $this->bet('Arsenal v Chelsea');
        $everton = $this->bet('Everton v Leeds');
        $celtic = $this->bet('Celtic v Rangers');
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->call('startSelecting')
            ->set('selected', [(string) $arsenal->id, (string) $everton->id])
            ->assertSee('2 bets selected')
            ->call('deleteSelected')
            ->assertSet('selecting', false)
            ->assertSet('selected', [])
            ->assertSee(['2 bets deleted. You can restore them from Settings → Deleted bets for 30 days.', 'Celtic v Rangers'])
            ->assertDontSee(['Arsenal v Chelsea', 'Everton v Leeds']);

        $this->assertSame([$celtic->id], $this->user->bets()->pluck('id')->all());
        $this->assertSoftDeleted($arsenal);
        $this->assertSoftDeleted($everton);
        $this->assertSame(2, Selection::query()->whereIn('bet_id', [$arsenal->id, $everton->id])->count());
    }

    public function test_select_all_takes_every_matching_bet_on_every_page_and_nothing_else(): void
    {
        $football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $tennis = Sport::factory()->for($this->user)->create(['name' => 'Tennis']);
        $footballBets = Bet::factory()->count(Index::PER_PAGE + 5)->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'sport_id' => $football->id]), 'selections')
            ->create();
        $tennisBet = $this->bet('Nadal v Federer', ['sport_id' => $tennis->id]);
        $settledFootballBet = Bet::factory()->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'sport_id' => $football->id]), 'selections')
            ->settled()
            ->create();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('sport', (string) $football->id)
            ->call('startSelecting')
            ->call('selectAllMatching')
            ->assertSee(['25 bets selected', 'Clear selection'])
            ->call('deleteSelected')
            ->assertSee('25 bets deleted.');

        $this->assertSame(0, Bet::query()->whereKey($footballBets->modelKeys())->count());
        $this->assertSame([$tennisBet->id, $settledFootballBet->id], $this->user->bets()->orderBy('id')->pluck('id')->all());
    }

    public function test_a_new_search_or_filter_clears_the_selection(): void
    {
        $bet = $this->bet('Arsenal v Chelsea');
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->call('startSelecting')
            ->set('selected', [(string) $bet->id])
            ->set('search', 'Arsenal')
            ->assertSet('selected', [])
            ->call('selectAllMatching')
            ->set('status', 'all')
            ->assertSet('selectsAllMatching', false)
            ->assertSet('selecting', true);
    }

    public function test_a_tag_is_added_to_the_selected_bets_that_dont_have_it(): void
    {
        $value = Tag::factory()->for($this->user)->create(['name' => 'Value']);
        $tagged = $this->bet('Arsenal v Chelsea');
        $tagged->tags()->attach($value);
        $untagged = $this->bet('Everton v Leeds');
        $notSelected = $this->bet('Celtic v Rangers');
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->call('startSelecting')
            ->set('selected', [(string) $tagged->id, (string) $untagged->id])
            ->set('bulkTag', (string) $value->id)
            ->call('addTagToSelected')
            ->assertHasNoErrors()
            ->assertSet('selecting', false)
            ->assertSee('Tag Value added to 1 bet.');

        $this->assertEqualsCanonicalizing([$tagged->id, $untagged->id], $value->bets()->pluck('bets.id')->all());
        $this->assertFalse($notSelected->tags()->exists());
        $this->assertDatabaseHas('bet_tag', ['bet_id' => $untagged->id, 'tag_id' => $value->id, 'user_id' => $this->user->id]);
    }

    public function test_a_tag_is_removed_from_the_selected_bets(): void
    {
        $value = Tag::factory()->for($this->user)->create(['name' => 'Value']);
        $first = $this->bet('Arsenal v Chelsea');
        $second = $this->bet('Everton v Leeds');
        $notSelected = $this->bet('Celtic v Rangers');
        $withoutTag = $this->bet('Leeds v Burnley');
        foreach ([$first, $second, $notSelected] as $bet) {
            $bet->tags()->attach($value);
        }
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('tag', (string) $value->id)
            ->call('startSelecting')
            ->set('selected', [(string) $first->id, (string) $second->id, (string) $withoutTag->id])
            ->set('bulkTag', (string) $value->id)
            ->call('removeTagFromSelected')
            ->assertSee(['Tag Value removed from 2 bets.', 'Celtic v Rangers'])
            ->assertDontSee('Arsenal v Chelsea');

        $this->assertSame([$notSelected->id], $value->bets()->pluck('bets.id')->all());
    }

    public function test_the_tag_must_be_one_of_the_users_own(): void
    {
        $bet = $this->bet('Arsenal v Chelsea');
        $otherTag = Tag::factory()->create(['name' => 'Their tag']);
        $this->actingAs($this->user);

        $component = Livewire::test(Index::class)
            ->call('startSelecting')
            ->set('selected', [(string) $bet->id]);

        $component->set('bulkTag', '')
            ->call('addTagToSelected')
            ->assertHasErrors(['bulkTag' => 'Choose one of your tags.']);

        $component->set('bulkTag', (string) $otherTag->id)
            ->call('addTagToSelected')
            ->assertHasErrors(['bulkTag' => 'Choose one of your tags.'])
            ->assertSet('selecting', true);

        $this->assertFalse($bet->tags()->exists());
        $this->assertFalse($otherTag->bets()->exists());
    }

    public function test_another_users_bets_are_never_changed_by_posted_ids(): void
    {
        $own = $this->bet('Arsenal v Chelsea');
        $ownTag = Tag::factory()->for($this->user)->create(['name' => 'Value']);
        $theirs = Bet::factory()->create();
        $theirs->tags()->attach(Tag::factory()->for($theirs->user)->create());
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->call('startSelecting')
            ->set('selected', [(string) $theirs->id, 'not an id'])
            ->set('bulkTag', (string) $ownTag->id)
            ->call('addTagToSelected')
            ->assertSee('Tag Value added to 0 bets.')
            ->call('startSelecting')
            ->set('selected', [(string) $theirs->id, (string) $own->id])
            ->call('deleteSelected')
            ->assertSee('1 bet deleted.');

        $this->assertSoftDeleted($own);
        $this->assertNotSoftDeleted($theirs);
        $this->assertSame(1, $theirs->tags()->count());
        $this->assertFalse($ownTag->bets()->exists());
    }

    public function test_the_bulk_actions_refuse_another_users_tag(): void
    {
        $bet = $this->bet('Arsenal v Chelsea');
        $otherTag = Tag::factory()->create();

        // The bets list only ever passes the user's own tags; this guards other callers.
        $this->expectException(InvalidArgumentException::class);

        app(BulkBetActions::class)->addTag($this->user, $otherTag, [$bet->id]);
    }

    public function test_the_import_filter_lists_the_users_imports_and_shows_only_that_import(): void
    {
        $this->travelTo('2026-11-20 09:30:00');
        $this->bet('Older import bet', bet: ['import_batch' => '01JOLDERIMPORT000000000000']);
        $this->travelTo('2026-11-25 18:05:00');
        $this->bet('Newer import one', bet: ['import_batch' => '01JNEWERIMPORT000000000000']);
        $this->bet('Newer import two', bet: ['import_batch' => '01JNEWERIMPORT000000000000']);
        $this->bet('Entered by hand');
        Bet::factory()->inImportBatch('01JTHEIRIMPORT000000000000')->create();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Import', '25 Nov 2026 19:05 · 2 bets', '20 Nov 2026 10:30 · 1 bet'])
            ->set('importBatch', '01JNEWERIMPORT000000000000')
            ->assertSee(['Imported 25 Nov 2026 19:05', 'Newer import one', 'Newer import two', '2 bets'])
            ->assertDontSee(['Older import bet', 'Entered by hand'])
            ->set('importBatch', '01JTHEIRIMPORT000000000000')
            ->assertSee(['Import: unknown', 'No open bets match these filters']);
    }

    public function test_an_import_without_bets_left_is_no_longer_filtered(): void
    {
        $this->bet('Imported bet', bet: ['import_batch' => '01JIMPORT00000000000000000']);
        $this->bet('Entered by hand');
        $this->actingAs($this->user);

        Livewire::withQueryParams(['batch' => '01JIMPORT00000000000000000'])
            ->test(Index::class)
            ->call('selectAllMatching')
            ->call('deleteSelected')
            ->assertSet('importBatch', '')
            ->assertSee(['1 bet deleted.', 'Entered by hand'])
            ->assertDontSee('Imported 27 Nov');
    }

    /**
     * An open single at the user's bookmaker.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $bet
     */
    private function bet(string $eventName, array $selection = [], array $bet = []): Bet
    {
        return Bet::factory()
            ->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => $eventName, 'pick' => 'Home', ...$selection]), 'selections')
            ->create($bet);
    }
}
