<?php

namespace Tests\Unit\Domain\Export;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Export\BetsCsv;
use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bets are built in memory with their relations set, as the export loads them; the
 * formatter never touches the database. The app is booted only for the models' casts.
 */
class BetsCsvTest extends TestCase
{
    /**
     * @return array<string, array{int|null, string}>
     */
    public static function amounts(): array
    {
        return [
            'whole and cents' => [1250, '12.50'],
            'one cent' => [1, '0.01'],
            'negative sub-unit' => [-5, '-0.05'],
            'negative' => [-10000, '-100.00'],
            'zero' => [0, '0.00'],
            'large, no thousands separator' => [123456789, '1234567.89'],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('amounts')]
    public function test_money_is_in_major_units_with_a_decimal_point(?int $minorUnits, string $expected): void
    {
        $this->assertSame($expected, BetsCsv::money($minorUnits));
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function decimals(): array
    {
        return [
            'odds with a trailing zero' => ['1.850', '1.85'],
            'odds with three decimals' => ['4.095', '4.095'],
            'whole odds' => ['2.000', '2'],
            'total odds' => ['2.7000', '2.7'],
            'exchange rate of one' => ['1.00000000', '1'],
            'exchange rate' => ['7.46040000', '7.4604'],
            'percentage' => ['55.50', '55.5'],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('decimals')]
    public function test_decimals_drop_trailing_zeros(?string $value, string $expected): void
    {
        $this->assertSame($expected, BetsCsv::decimal($value));
    }

    public function test_dates_are_iso_8601_in_utc(): void
    {
        $this->assertSame('2026-11-23T14:05:00Z', BetsCsv::dateTime(Carbon::parse('2026-11-23 14:05:00', 'UTC')));
        $this->assertSame('2026-11-23T13:05:00Z', BetsCsv::dateTime(Carbon::parse('2026-11-23 14:05:00', 'Europe/Copenhagen')));
        $this->assertSame('', BetsCsv::dateTime(null));
    }

    public function test_names_are_sorted_and_joined(): void
    {
        $this->assertSame('Arsenal | Chelsea | arsenal', BetsCsv::names(['arsenal', 'Chelsea', 'Arsenal']));
        $this->assertSame('Value', BetsCsv::names(['Value']));
        $this->assertSame('', BetsCsv::names([]));
    }

    public function test_the_header_lists_bet_fields_then_selection_fields(): void
    {
        $header = BetsCsv::header();

        $this->assertSame('bet_id', $header[0]);
        $this->assertSame('result', $header[count($header) - 1]);
        $this->assertSame(array_values(array_unique($header)), $header);
        $this->assertLessThan(array_search('selection_position', $header, true), array_search('notes', $header, true));
    }

    public function test_a_settled_single_with_every_field(): void
    {
        $bet = $this->bet([
            'id' => 42,
            'external_ref' => 'sportmarket:2384015187',
            'currency' => Currency::Dkk,
            'exchange_rate' => '0.13400000',
            'stake' => 10000,
            'total_odds' => '2.1000',
            'probability' => '55.00',
            'bet_value' => '115.50',
            'status' => BetStatus::Settled,
            'settled_at' => Carbon::parse('2026-11-22 21:50:00', 'UTC'),
            'payout' => 21000,
            'profit' => 11000,
            'notes' => 'Team news, "late" call',
        ], tipster: 'Model A', tags: ['Value', 'Live']);
        $bet->setRelation('selections', new Collection([$this->selection([
            'position' => 1,
            'odds' => '2.100',
            'closing_odds' => '1.950',
            'result' => SelectionResult::Won,
        ], competition: 'Premier League', market: '1X2', teams: ['Chelsea', 'Arsenal'])]));

        $rows = (new BetsCsv(Currency::Eur))->rows($bet);

        $this->assertCount(1, $rows);
        $this->assertSame([
            'bet_id' => '42',
            'external_ref' => 'sportmarket:2384015187',
            'type' => 'single',
            'placed_at' => '2026-11-22T18:30:00Z',
            'bookmaker' => 'Danske Spil',
            'currency' => 'DKK',
            'base_currency' => 'EUR',
            'exchange_rate' => '0.134',
            'stake' => '100.00',
            'is_free_bet' => 'no',
            'total_odds' => '2.1',
            'probability' => '55',
            'bet_value' => '115.5',
            'status' => 'settled',
            'settled_at' => '2026-11-22T21:50:00Z',
            'payout' => '210.00',
            'payout_is_manual' => 'no',
            'profit' => '110.00',
            'tipster' => 'Model A',
            'tags' => 'Live | Value',
            'notes' => 'Team news, "late" call',
            'selection_position' => '1',
            'sport' => 'Football',
            'competition' => 'Premier League',
            'market' => '1X2',
            'teams' => 'Arsenal | Chelsea',
            'event_name' => 'Arsenal v Chelsea',
            'event_starts_at' => '2026-11-22T20:00:00Z',
            'pick' => 'Arsenal',
            'odds' => '2.1',
            'closing_odds' => '1.95',
            'result' => 'won',
        ], array_combine(BetsCsv::header(), $rows[0]));
    }

    public function test_an_open_bet_has_no_settlement_values_and_empty_optional_fields(): void
    {
        $bet = $this->bet();
        $bet->setRelation('selections', new Collection([$this->selection(['event_starts_at' => null])]));

        $row = array_combine(BetsCsv::header(), (new BetsCsv(Currency::Eur))->rows($bet)[0]);

        $this->assertSame('open', $row['status']);
        $this->assertSame('', $row['settled_at']);
        $this->assertSame('', $row['payout']);
        $this->assertSame('', $row['profit']);
        $this->assertSame('', $row['result']);
        $this->assertSame('no', $row['payout_is_manual']);

        foreach (['external_ref', 'probability', 'bet_value', 'tipster', 'tags', 'notes', 'competition', 'market', 'teams', 'event_starts_at', 'closing_odds'] as $column) {
            $this->assertSame('', $row[$column], $column);
        }
    }

    public function test_an_accumulator_gives_one_row_per_selection_with_the_bets_fields_repeated(): void
    {
        $bet = $this->bet([
            'type' => BetType::Accumulator,
            'total_odds' => '5.4000',
            'status' => BetStatus::Settled,
            'settled_at' => Carbon::parse('2026-11-23 10:00:00', 'UTC'),
            'payout' => 54000,
            'profit' => 44000,
        ], tags: ['Acca']);
        $bet->setRelation('selections', new Collection([
            $this->selection(['position' => 1, 'event_name' => 'A v B', 'odds' => '1.500', 'result' => SelectionResult::Won]),
            $this->selection(['position' => 2, 'event_name' => 'C v D', 'odds' => '2.000', 'result' => SelectionResult::Won]),
            $this->selection(['position' => 3, 'event_name' => 'E v F', 'odds' => '1.800', 'result' => SelectionResult::Won]),
        ]));

        $rows = array_map(
            fn (array $row): array => array_combine(BetsCsv::header(), $row),
            (new BetsCsv(Currency::Eur))->rows($bet),
        );

        $this->assertCount(3, $rows);
        $this->assertSame(['1', '2', '3'], array_column($rows, 'selection_position'));
        $this->assertSame(['A v B', 'C v D', 'E v F'], array_column($rows, 'event_name'));
        $this->assertSame(['1.5', '2', '1.8'], array_column($rows, 'odds'));

        $betColumns = array_slice(BetsCsv::header(), 0, array_search('selection_position', BetsCsv::header(), true));

        foreach ($betColumns as $column) {
            $this->assertCount(1, array_unique(array_column($rows, $column)), $column);
        }

        $this->assertSame('accumulator', $rows[0]['type']);
        $this->assertSame('5.4', $rows[0]['total_odds']);
        $this->assertSame('540.00', $rows[2]['payout']);
        $this->assertSame('Acca', $rows[2]['tags']);
    }

    public function test_a_free_bet(): void
    {
        $bet = $this->bet([
            'is_free_bet' => true,
            'stake' => 1000,
            'total_odds' => '3.0000',
            'status' => BetStatus::Settled,
            'settled_at' => Carbon::parse('2026-11-23 10:00:00', 'UTC'),
            'payout' => 2000,
            'profit' => 2000,
        ]);
        $bet->setRelation('selections', new Collection([$this->selection(['odds' => '3.000', 'result' => SelectionResult::Won])]));

        $row = array_combine(BetsCsv::header(), (new BetsCsv(Currency::Eur))->rows($bet)[0]);

        $this->assertSame('yes', $row['is_free_bet']);
        $this->assertSame('10.00', $row['stake']);
        $this->assertSame('20.00', $row['payout']);
        $this->assertSame('20.00', $row['profit']);
    }

    public function test_a_settled_bet_with_a_manual_payout_and_a_loss_below_one_unit(): void
    {
        $bet = $this->bet([
            'stake' => 1000,
            'total_odds' => '1.9500',
            'status' => BetStatus::Settled,
            'settled_at' => Carbon::parse('2026-11-23 10:00:00', 'UTC'),
            'payout' => 995,
            'payout_is_manual' => true,
            'profit' => -5,
        ]);
        $bet->setRelation('selections', new Collection([$this->selection(['odds' => '1.950', 'result' => SelectionResult::Void])]));

        $row = array_combine(BetsCsv::header(), (new BetsCsv(Currency::Eur))->rows($bet)[0]);

        $this->assertSame('yes', $row['payout_is_manual']);
        $this->assertSame('9.95', $row['payout']);
        $this->assertSame('-0.05', $row['profit']);
        $this->assertSame('void', $row['result']);
    }

    public function test_a_bet_value_entered_without_a_probability(): void
    {
        $bet = $this->bet(['probability' => null, 'bet_value' => '104.20']);
        $bet->setRelation('selections', new Collection([$this->selection()]));

        $row = array_combine(BetsCsv::header(), (new BetsCsv(Currency::Eur))->rows($bet)[0]);

        $this->assertSame('', $row['probability']);
        $this->assertSame('104.2', $row['bet_value']);
    }

    /**
     * An open EUR single at a bookmaker with the given attributes overridden.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $tags
     */
    private function bet(array $attributes = [], ?string $tipster = null, array $tags = []): Bet
    {
        $bet = (new Bet)->forceFill([
            'id' => 1,
            'type' => BetType::Single,
            'placed_at' => Carbon::parse('2026-11-22 18:30:00', 'UTC'),
            'currency' => Currency::Eur,
            'exchange_rate' => '1.00000000',
            'stake' => 10000,
            'is_free_bet' => false,
            'total_odds' => '2.1000',
            'probability' => null,
            'bet_value' => null,
            'status' => BetStatus::Open,
            'settled_at' => null,
            'payout' => null,
            'payout_is_manual' => false,
            'profit' => null,
            'notes' => null,
            'external_ref' => null,
            ...$attributes,
        ]);

        $bet->setRelation('bookmaker', (new Bookmaker)->forceFill(['name' => 'Danske Spil']));
        $bet->setRelation('tipster', $tipster === null ? null : (new Tipster)->forceFill(['name' => $tipster]));
        $bet->setRelation('tags', new Collection(array_map(fn (string $name): Tag => (new Tag)->forceFill(['name' => $name]), $tags)));

        return $bet;
    }

    /**
     * A pending football selection with the given attributes overridden.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $teams
     */
    private function selection(array $attributes = [], ?string $competition = null, ?string $market = null, array $teams = []): Selection
    {
        $selection = (new Selection)->forceFill([
            'position' => 1,
            'event_name' => 'Arsenal v Chelsea',
            'event_starts_at' => Carbon::parse('2026-11-22 20:00:00', 'UTC'),
            'pick' => 'Arsenal',
            'odds' => '2.100',
            'closing_odds' => null,
            'result' => null,
            ...$attributes,
        ]);

        $selection->setRelation('sport', (new Sport)->forceFill(['name' => 'Football']));
        $selection->setRelation('competition', $competition === null ? null : (new Competition)->forceFill(['name' => $competition]));
        $selection->setRelation('market', $market === null ? null : (new Market)->forceFill(['name' => $market]));
        $selection->setRelation('teams', new Collection(array_map(fn (string $name): Team => (new Team)->forceFill(['name' => $name]), $teams)));

        return $selection;
    }
}
