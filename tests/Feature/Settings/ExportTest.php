<?php

namespace Tests\Feature\Settings;

use App\Domain\Currency;
use App\Domain\Export\BetsCsv;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Settings\DeleteUserForm;
use App\Livewire\Settings\Export;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-23 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('settings.export'))->assertRedirect(route('login'));
        $this->get(route('settings.export.download'))->assertRedirect(route('login'));
    }

    public function test_the_page_is_linked_from_settings_and_links_to_the_download(): void
    {
        $user = User::factory()->create();
        Bet::factory()->for($user)->count(2)->create();

        $this->actingAs($user);

        $this->get(route('settings.profile'))->assertOk()->assertSee(route('settings.export'));
        $this->get(route('settings.export'))
            ->assertOk()
            ->assertSee('You have 2 bets.')
            ->assertSee('href="'.route('settings.export.download').'"', false)
            ->assertDontSee('nothing to export');
    }

    public function test_the_download_does_not_need_a_verified_email(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(route('settings.export'))->assertOk();
        $this->get(route('settings.export.download'))->assertOk();
    }

    public function test_a_user_without_bets_is_told_and_gets_only_the_header_row(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Export::class)->assertSee('nothing to export');

        $this->assertSame([BetsCsv::header()], $this->download());
    }

    public function test_the_download_is_a_utf8_csv_attachment(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('settings.export.download'));

        $response->assertOk()->assertDownload('betledger-bets-2026-11-23.csv');
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\u{FEFF}bet_id,external_ref,", (string) $response->streamedContent());
        $this->assertStringEndsWith("result\r\n", (string) $response->streamedContent());
    }

    public function test_single_and_accumulator_bets_with_their_details(): void
    {
        $user = User::factory()->create(['base_currency' => Currency::Eur]);
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.13400000')->create(['name' => 'Danske Spil']);
        $football = Sport::factory()->for($user)->create(['name' => 'Fodbold']);

        $single = Bet::factory()->forBookmaker($bookmaker)->single('1.850')->settled(SelectionResult::Won)->create([
            'placed_at' => Carbon::parse('2026-11-20 18:30:00', 'UTC'),
            'stake' => 10000,
            'tipster_id' => Tipster::factory()->for($user)->create(['name' => 'Model Æ'])->id,
            'probability' => '60.00',
            'bet_value' => '111.00',
            'notes' => 'Kampen i Århus',
        ]);
        $single->tags()->attach([
            Tag::factory()->for($user)->create(['name' => 'Value'])->id,
            Tag::factory()->for($user)->create(['name' => 'Live'])->id,
        ]);
        $selection = $single->selections[0];
        $selection->update([
            'sport_id' => $football->id,
            'competition_id' => Competition::factory()->forSport($football)->create(['name' => 'Superliga'])->id,
            'market_id' => Market::factory()->for($user)->create(['name' => '1X2'])->id,
            'event_name' => 'AGF v Brøndby',
            'event_starts_at' => Carbon::parse('2026-11-20 19:00:00', 'UTC'),
            'pick' => 'AGF',
            'closing_odds' => '1.800',
        ]);
        $selection->teams()->attach([
            Team::factory()->forSport($football)->create(['name' => 'Brøndby'])->id,
            Team::factory()->forSport($football)->create(['name' => 'AGF'])->id,
        ]);

        $accumulator = Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00', '1.80'])->withResults(SelectionResult::Won, SelectionResult::Void, null)->create([
            'placed_at' => Carbon::parse('2026-11-21 09:00:00', 'UTC'),
            'stake' => 5000,
        ]);

        $this->actingAs($user);

        $rows = $this->downloadAssociative();

        $this->assertCount(4, $rows);

        $this->assertSame([
            'bet_id' => (string) $single->id,
            'external_ref' => '',
            'type' => 'single',
            'placed_at' => '2026-11-20T18:30:00Z',
            'bookmaker' => 'Danske Spil',
            'currency' => 'DKK',
            'base_currency' => 'EUR',
            'exchange_rate' => '0.134',
            'stake' => '100.00',
            'is_free_bet' => 'no',
            'total_odds' => '1.85',
            'probability' => '60',
            'bet_value' => '111',
            'status' => 'settled',
            'settled_at' => '2026-11-20T21:30:00Z',
            'payout' => '185.00',
            'payout_is_manual' => 'no',
            'profit' => '85.00',
            'tipster' => 'Model Æ',
            'tags' => 'Live | Value',
            'notes' => 'Kampen i Århus',
            'selection_position' => '1',
            'sport' => 'Fodbold',
            'competition' => 'Superliga',
            'market' => '1X2',
            'teams' => 'AGF | Brøndby',
            'event_name' => 'AGF v Brøndby',
            'event_starts_at' => '2026-11-20T19:00:00Z',
            'pick' => 'AGF',
            'odds' => '1.85',
            'closing_odds' => '1.8',
            'result' => 'won',
        ], $rows[0]);

        $legs = array_slice($rows, 1);

        $this->assertSame(array_fill(0, 3, (string) $accumulator->id), array_column($legs, 'bet_id'));
        $this->assertSame(array_fill(0, 3, 'accumulator'), array_column($legs, 'type'));
        $this->assertSame(array_fill(0, 3, '5.4'), array_column($legs, 'total_odds'));
        $this->assertSame(array_fill(0, 3, 'open'), array_column($legs, 'status'));
        $this->assertSame(array_fill(0, 3, ''), array_column($legs, 'profit'));
        $this->assertSame(['1', '2', '3'], array_column($legs, 'selection_position'));
        $this->assertSame(['1.5', '2', '1.8'], array_column($legs, 'odds'));
        $this->assertSame(['won', 'void', ''], array_column($legs, 'result'));
    }

    public function test_bets_are_ordered_by_placing_time(): void
    {
        $user = User::factory()->create();
        $later = Bet::factory()->for($user)->create(['placed_at' => Carbon::parse('2026-11-22 10:00:00', 'UTC')]);
        $earlier = Bet::factory()->for($user)->create(['placed_at' => Carbon::parse('2026-11-01 10:00:00', 'UTC')]);

        $this->actingAs($user);

        $this->assertSame([(string) $earlier->id, (string) $later->id], array_column($this->downloadAssociative(), 'bet_id'));
    }

    public function test_notes_with_commas_quotes_and_line_breaks_round_trip(): void
    {
        $user = User::factory()->create();
        $notes = "Took the \"early\" price, then\nhedged; 2,5 units\r\nback = \\ ok";
        Bet::factory()->for($user)->create(['notes' => $notes]);

        $this->actingAs($user);

        $this->assertSame($notes, $this->downloadAssociative()[0]['notes']);
    }

    public function test_another_users_bets_and_list_entries_are_never_exported(): void
    {
        $user = User::factory()->create();
        Bet::factory()->for($user)->accumulator(['1.50', '2.00'])->create();

        $other = User::factory()->create();
        $otherBookmaker = Bookmaker::factory()->for($other)->create(['name' => 'Their Bookie']);
        $otherSport = Sport::factory()->for($other)->create(['name' => 'Their Sport']);
        $otherBet = Bet::factory()->forBookmaker($otherBookmaker)->single()->create([
            'tipster_id' => Tipster::factory()->for($other)->create(['name' => 'Their Tipster'])->id,
            'notes' => 'Their secret note',
        ]);
        $otherBet->tags()->attach(Tag::factory()->for($other)->create(['name' => 'Their Tag']));
        $otherBet->selections[0]->update(['sport_id' => $otherSport->id, 'event_name' => 'Their Event']);
        $otherBet->selections[0]->teams()->attach(Team::factory()->forSport($otherSport)->create(['name' => 'Their Team']));

        $this->actingAs($user);

        $response = $this->get(route('settings.export.download'));
        $content = (string) $response->streamedContent();

        foreach (['Their Bookie', 'Their Sport', 'Their Tipster', 'Their secret note', 'Their Tag', 'Their Event', 'Their Team'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $content);
        }

        $rows = $this->downloadAssociative();
        $this->assertCount(2, $rows);
        $this->assertNotContains((string) $otherBet->id, array_column($rows, 'bet_id'));
    }

    public function test_the_relations_are_eager_loaded_so_queries_do_not_grow_with_the_bets(): void
    {
        $user = User::factory()->create();
        $this->betWithEverything($user);

        $this->actingAs($user);
        Model::preventLazyLoading();

        $queriesForOneBet = $this->countQueries(fn () => $this->download());

        foreach (range(1, 5) as $ignored) {
            $this->betWithEverything($user);
        }

        $queriesForSixBets = $this->countQueries(fn () => $this->download());

        $this->assertSame($queriesForOneBet, $queriesForSixBets);
        $this->assertCount(13, $this->download());
    }

    public function test_the_delete_account_dialog_links_to_the_download(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->assertSee('Download your bets first')
            ->assertSee('href="'.route('settings.export.download').'"', false);
    }

    /**
     * An accumulator of two legs with a tipster, tags, competition, market and teams.
     */
    private function betWithEverything(User $user): void
    {
        $sport = Sport::factory()->for($user)->create();
        $bet = Bet::factory()->for($user)->accumulator(['1.50', '2.00'])->create([
            'tipster_id' => Tipster::factory()->for($user)->create()->id,
        ]);
        $bet->tags()->attach(Tag::factory()->for($user)->count(2)->create());

        foreach ($bet->selections as $selection) {
            $selection->update([
                'sport_id' => $sport->id,
                'competition_id' => Competition::factory()->forSport($sport)->create()->id,
                'market_id' => Market::factory()->for($user)->create()->id,
            ]);
            $selection->teams()->attach(Team::factory()->forSport($sport)->count(2)->create());
        }
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $before = $count;
        $callback();

        return $count - $before;
    }

    /**
     * The acting user's export, parsed: the header row, then one row per selection.
     *
     * @return list<list<string>>
     */
    private function download(): array
    {
        return $this->parse($this->get(route('settings.export.download'))->assertOk());
    }

    /**
     * The acting user's export as rows keyed by column name, without the header row.
     *
     * @return list<array<string, string>>
     */
    private function downloadAssociative(): array
    {
        $rows = $this->download();
        $header = array_shift($rows);

        $this->assertSame(BetsCsv::header(), $header);

        return array_map(fn (array $row): array => array_combine($header, $row), $rows);
    }

    /**
     * Parse the CSV as RFC 4180 (quoted fields may hold line breaks, so it is read with
     * fgetcsv rather than split into lines).
     *
     * @param  TestResponse<Response>  $response
     * @return list<list<string>>
     */
    private function parse(TestResponse $response): array
    {
        $content = (string) $response->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}", $content);

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, strlen("\u{FEFF}")));
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = array_map(fn (?string $value): string => (string) $value, $row);
        }

        fclose($stream);

        return $rows;
    }
}
