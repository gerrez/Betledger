<?php

namespace Tests\Feature\Statistics;

use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\Dimension;
use App\Livewire\Bets\Index as BetsIndex;
use App\Livewire\Statistics\Index;
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
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each breakdown row and odds range links to the bets list, which must show exactly the
 * row's settled bets.
 */
class StatisticsRowLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Sport $football;

    private Sport $tennis;

    private Tipster $tipster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');
        $this->user = User::factory()->create();
        $this->football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $this->tennis = Sport::factory()->for($this->user)->create(['name' => 'Tennis']);
        $this->tipster = Tipster::factory()->for($this->user)->create(['name' => 'Jens']);
        $this->seedBets();
    }

    /**
     * @return array<string, array{Dimension}>
     */
    public static function dimensions(): array
    {
        return collect(Dimension::cases())->mapWithKeys(fn (Dimension $dimension): array => [$dimension->label() => [$dimension]])->all();
    }

    #[DataProvider('dimensions')]
    public function test_each_breakdown_row_links_to_a_list_of_its_settled_bets(Dimension $dimension): void
    {
        $this->actingAs($this->user);

        $rows = Livewire::test(Index::class)->set('dimension', $dimension->value)->viewData('breakdown');

        $this->assertGreaterThan(1, count($rows));
        $this->assertSame($dimension->hasNone(), in_array(true, array_column($rows, 'isNone'), true), 'The data has a "none" row where the dimension can have one.');
        $this->assertLinksShowTheRowsBets($rows);
    }

    public function test_each_odds_range_links_to_a_list_of_its_settled_bets(): void
    {
        $this->actingAs($this->user);

        $bands = Livewire::test(Index::class)->viewData('oddsBands');

        $this->assertSame([1, 2, 1, 1, 1, 1], array_map(fn (array $band): int => $band['totals']->settledBets, $bands), 'Every range has bets, including ones on its bounds.');
        $this->assertLinksShowTheRowsBets($bands);
        $this->assertStringContainsString('odds=1.51-2.00', (string) $bands[1]['href']);
    }

    public function test_the_links_keep_the_pages_date_range_and_filters(): void
    {
        $this->actingAs($this->user);

        $page = Livewire::test(Index::class)
            ->call('useRange', 'custom')
            ->set('from', '2026-09-01')
            ->set('to', '2026-10-20')
            ->set('tipster', (string) $this->tipster->id)
            ->call('toggleFreeBets');

        foreach (Dimension::cases() as $dimension) {
            $rows = $page->set('dimension', $dimension->value)->viewData('breakdown');

            $this->assertNotSame([], $rows);
            $this->assertLinksShowTheRowsBets($rows);
        }

        $bands = $page->viewData('oddsBands');
        $this->assertLinksShowTheRowsBets($bands);

        $href = (string) $bands[1]['href'];
        foreach (['status=settled', 'from=2026-09-01', 'to=2026-10-20', "tipster={$this->tipster->id}", 'free=exclude', 'odds=1.51-2.00'] as $parameter) {
            $this->assertStringContainsString($parameter, $href);
        }
    }

    public function test_a_row_is_not_linked_when_the_page_filters_its_dimension_by_another_value(): void
    {
        $this->actingAs($this->user);

        $rows = Livewire::test(Index::class)
            ->set('sport', (string) $this->football->id)
            ->set('dimension', 'sport')
            ->viewData('breakdown');

        $linked = array_column($rows, 'href', 'label');

        $this->assertSame(['Football', 'Tennis'], array_keys($linked));
        $this->assertNull($linked['Tennis'], 'The accumulator with a tennis leg also has a football leg; a list filtered by tennis alone would show more bets.');
        $this->assertLinksShowTheRowsBets($rows, unlinked: ['Tennis']);
    }

    public function test_rows_without_settled_bets_are_not_linked_and_the_page_shows_the_links(): void
    {
        $this->actingAs($this->user);

        $page = Livewire::test(Index::class);
        $bands = $page->viewData('oddsBands');

        $this->assertNotNull($bands[0]['href']);
        $page->assertSeeHtml('href="'.e((string) $bands[0]['href']).'"')
            ->assertSee('Total odds 1.01–1.50: view the 1 settled bet');

        Bet::query()->where('total_odds', '<=', '1.50')->delete();

        $bands = Livewire::test(Index::class)->viewData('oddsBands');
        $this->assertSame(0, $bands[0]['totals']->settledBets);
        $this->assertNull($bands[0]['href']);
    }

    /**
     * Every row with settled bets (except the unlinked ones) links to the bets list,
     * whose Settled tab then shows as many bets as the row counts; rows without settled
     * bets aren't linked.
     *
     * @param  list<array{label: string, href: string|null, totals: object}>  $rows
     * @param  list<string>  $unlinked  labels of rows that must not be linked
     */
    private function assertLinksShowTheRowsBets(array $rows, array $unlinked = []): void
    {
        foreach ($rows as $row) {
            $settledBets = $row['totals']->settledBets;

            if ($settledBets === 0 || in_array($row['label'], $unlinked, true)) {
                $this->assertNull($row['href'], "Row \"{$row['label']}\" is not linked");

                continue;
            }

            $this->assertNotNull($row['href'], "Row \"{$row['label']}\" is linked");
            $this->assertStringStartsWith(route('bets.index').'?', $row['href']);
            parse_str((string) parse_url($row['href'], PHP_URL_QUERY), $query);

            $this->assertSame('settled', $query['status'] ?? null);
            $this->assertSame(
                $settledBets,
                Livewire::withQueryParams($query)->test(BetsIndex::class)->viewData('bets')->total(),
                "Row \"{$row['label']}\" links to {$row['href']}",
            );
        }
    }

    /**
     * Settled bets that cover every dimension's values and a "none" row where there can be
     * one, an odds range each (on and next to the bounds), a free bet, open bets and bets
     * outside the default 90 days that the links must not pick up, and another user's bets.
     */
    private function seedBets(): void
    {
        $unibet = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
        $bet365 = Bookmaker::factory()->for($this->user)->create(['name' => 'Bet365']);
        $premierLeague = Competition::factory()->for($this->user)->forSport($this->football)->create(['name' => 'Premier League']);
        $arsenal = Team::factory()->for($this->user)->forSport($this->football)->create(['name' => 'Arsenal']);
        $chelsea = Team::factory()->for($this->user)->forSport($this->football)->create(['name' => 'Chelsea']);
        $matchOdds = Market::factory()->for($this->user)->create(['name' => 'Match odds']);
        $value = Tag::factory()->for($this->user)->create(['name' => 'Value']);
        $live = Tag::factory()->for($this->user)->create(['name' => 'Live']);
        $football = ['sport_id' => $this->football->id, 'competition_id' => $premierLeague->id, 'market_id' => $matchOdds->id];
        $tennis = ['sport_id' => $this->tennis->id];

        $single = $this->single($unibet, '1.50', $football, ['tipster_id' => $this->tipster->id], SelectionResult::Won);
        $single->selections[0]->teams()->attach([$arsenal->id, $chelsea->id]);
        $single->tags()->attach($value);

        $accumulator = Bet::factory()->forBookmaker($bet365)
            ->has(Selection::factory()->count(2)->state(new Sequence(
                ['position' => 1, 'odds' => '1.501', ...$football],
                ['position' => 2, 'odds' => '2.00', ...$tennis],
            )), 'selections')
            ->settled(SelectionResult::Lost)
            ->create(['placed_at' => '2026-10-10 12:00:00', 'tipster_id' => $this->tipster->id]);
        $accumulator->selections->firstWhere('position', 1)?->teams()->attach($arsenal);
        $accumulator->tags()->attach([$value->id, $live->id]);

        $this->single($unibet, '2.00', ['sport_id' => $this->football->id, 'market_id' => $matchOdds->id], [], SelectionResult::Won)->tags()->attach($live);
        $this->single($bet365, '3.00', $tennis, ['tipster_id' => $this->tipster->id], SelectionResult::Void);
        $this->single($unibet, '10.00', $tennis, ['is_free_bet' => true], SelectionResult::Lost);
        $this->single($bet365, '10.01', $football, ['tipster_id' => $this->tipster->id], SelectionResult::Lost);
        $this->single($unibet, '1.501', $football, ['tipster_id' => $this->tipster->id], SelectionResult::Won)->tags()->attach($value);

        $this->single($unibet, '2.00', $football, ['tipster_id' => $this->tipster->id], null);
        $this->single($unibet, '2.00', $tennis, [], SelectionResult::Won, '2026-05-01 12:00:00');

        $someoneElse = User::factory()->create();
        $theirBookmaker = Bookmaker::factory()->for($someoneElse)->create();
        foreach (['1.40', '2.00', '4.00'] as $odds) {
            Bet::factory()->forBookmaker($theirBookmaker)->single($odds)->settled()->create(['placed_at' => '2026-10-10 12:00:00']);
        }
    }

    /**
     * A single placed (and its event) on the given day, settled with the result unless null.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $bet
     */
    private function single(Bookmaker $bookmaker, string $odds, array $selection, array $bet, ?SelectionResult $result, string $placedAt = '2026-10-10 12:00:00'): Bet
    {
        $factory = Bet::factory()->forBookmaker($bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'odds' => $odds, ...$selection]), 'selections');

        return ($result === null ? $factory : $factory->settled($result))->create(['placed_at' => $placedAt, ...$bet]);
    }
}
