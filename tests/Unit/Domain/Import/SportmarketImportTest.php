<?php

namespace Tests\Unit\Domain\Import;

use App\Domain\Currency;
use App\Domain\Import\Sportmarket\ImportedBet;
use App\Domain\Import\Sportmarket\ImportedOrder;
use App\Domain\Import\Sportmarket\InvalidOrdersFile;
use App\Domain\Import\Sportmarket\OrderOutcome;
use App\Domain\Import\Sportmarket\SportmarketImport;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SportmarketImportTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/sportmarket/orders.csv';

    /**
     * A settled single fill, as in the fixture; tests override the columns they need.
     */
    private const ROW = [
        'Order Id' => '1000',
        'Order Placed' => '2026-10-21 20:26:44.880850+00:00',
        'Order Placer' => 'demo',
        'Bet ID' => '1',
        'Competition' => 'UEFA Champions League',
        'Game Date' => '2026-10-21',
        'Sport' => 'fb',
        'Home' => 'Sporting CP',
        'Away' => 'LASK Linz',
        'Bet Type' => 'Over 2.5 goals',
        'Bet Status' => 'settled',
        'Bookie' => 'fair',
        'Username' => '_x_',
        'User Currency' => 'EUR',
        'Stake (User Currency)' => '100.00',
        'Price' => '2.0000000000',
        'Order Stake' => '100.00',
        'Order Price' => '2.0000000000',
        'Reconciled P&L (User Currency)' => '100.00',
        'In Running' => 'No',
        'Selection' => 'over',
        'Market' => 'ou',
        'Country' => 'England',
        'Exchange Role' => '',
        'Event Name' => '',
    ];

    public function test_a_won_order_becomes_a_settled_single(): void
    {
        $bet = $this->fixtureBet('2384017276');

        $this->assertSame('sportmarket:2384017276', $bet->externalRef);
        $this->assertSame('2026-10-21 20:26:44.000000 UTC', $bet->placedAt->format('Y-m-d H:i:s.u e'));
        $this->assertSame(14857, $bet->stake);
        $this->assertSame('1.441', $bet->odds);
        $this->assertSame('Football', $bet->sport);
        $this->assertSame('UEFA Champions League', $bet->competition);
        $this->assertSame('Asian total goals', $bet->market);
        $this->assertSame(['Sporting CP', 'LASK Linz'], $bet->teams);
        $this->assertSame('Sporting CP v LASK Linz', $bet->eventName);
        $this->assertSame('AGAINST Under 3.5/4 (Asian)', $bet->pick);
        $this->assertSame(['In-play'], $bet->tags);
        $this->assertSame('Sportmarket order 2384017276 · 1 fill: fair', $bet->notes);
        $this->assertSame(SelectionResult::Won, $bet->result);
        $this->assertSame(21409, $bet->payout);
        $this->assertNull($bet->manualPayout);
        $this->assertSame('2026-10-21 23:59:00 UTC', $bet->settledAt->format('Y-m-d H:i:s e'));
    }

    /**
     * @return array<string, array{string, SelectionResult, int, int|null}>
     */
    public static function fixtureResults(): array
    {
        return [
            'lost' => ['2383987225', SelectionResult::Lost, 0, null],
            'half won' => ['2369692004', SelectionResult::HalfWon, 59999, null],
            'half lost' => ['2004325904', SelectionResult::HalfLost, 25000, null],
            'push' => ['2379336380', SelectionResult::Void, 15000, null],
            'a settled and a void fill' => ['2363215285', SelectionResult::Void, 14738, null],
            'P&L a few cents off the odds' => ['2355359715', SelectionResult::Won, 22450, 22450],
            'fills with different results' => ['2148345687', SelectionResult::Won, 16378, 16378],
        ];
    }

    #[DataProvider('fixtureResults')]
    public function test_the_result_is_the_one_nearest_to_the_reported_payout(string $orderId, SelectionResult $result, int $payout, ?int $manualPayout): void
    {
        $bet = $this->fixtureBet($orderId);

        $this->assertSame($result, $bet->result);
        $this->assertSame($payout, $bet->payout);
        $this->assertSame($manualPayout, $bet->manualPayout);
    }

    public function test_a_bet_not_placed_in_play_has_no_tag(): void
    {
        $bet = $this->fixtureBet('2369692004');

        $this->assertSame([], $bet->tags);
        $this->assertSame('Sportmarket order 2369692004 · 1 fill: molly', $bet->notes);
    }

    public function test_a_void_fill_counts_towards_stake_and_odds(): void
    {
        $bet = $this->fixtureBet('2363215285');

        $this->assertSame(14738, $bet->stake); // 15.35 + 132.03
        $this->assertSame('1.401', $bet->odds); // (15.35 × 1.405 + 132.03 × 1.400) / 147.38 = 1.40052…
        $this->assertSame('AGAINST Under 2.0 (Asian)', $bet->pick);
        $this->assertSame('2026-10-17 11:35:55', $bet->placedAt->format('Y-m-d H:i:s'));
        $this->assertSame('Sportmarket order 2363215285 · 2 fills: molly, fair', $bet->notes);
    }

    public function test_failed_fills_are_dropped_and_the_other_fills_are_combined(): void
    {
        $bet = $this->fixtureBet('2384015187');

        $this->assertSame(15001, $bet->stake); // the fills' stakes, not the Order Stake of 150.00
        $this->assertSame('1.403', $bet->odds);
        $this->assertSame('2026-10-21 20:20:31', $bet->placedAt->format('Y-m-d H:i:s')); // the earliest fill that didn't fail
        $this->assertSame('Over 3.5 goals', $bet->pick); // the largest fill, molly's 52.63
        $this->assertSame('Total goals', $bet->market);
        $this->assertSame('Sportmarket order 2384015187 · 6 fills: mbook ×4, molly, fair', $bet->notes);
        $this->assertSame(SelectionResult::Won, $bet->result);
        $this->assertSame(21047, $bet->payout); // 150.01 + 60.46
        $this->assertSame(21047, $bet->manualPayout); // calculated: 15001 × 1.403 = 21046.403
    }

    public function test_a_fill_of_one_cent_does_not_give_the_order_its_pick(): void
    {
        $bet = $this->fixtureBet('2148345687');

        $this->assertSame(11000, $bet->stake);
        $this->assertSame('1.489', $bet->odds);
        $this->assertSame('AGAINST Under 1.5 goals', $bet->pick);
        $this->assertSame('Sportmarket order 2148345687 · 2 fills: fair, betdex', $bet->notes);
    }

    public function test_an_order_whose_fills_all_failed_is_skipped(): void
    {
        $order = $this->fixtureOrder('2379759923');

        $this->assertSame(OrderOutcome::Skip, $order->outcome);
        $this->assertNull($order->bet);
        $this->assertSame('Every fill failed, so no bet was placed.', $order->reason);
    }

    public function test_an_order_with_only_one_fill_left_after_failures(): void
    {
        $bet = $this->fixtureBet('2018198934');

        $this->assertSame(19592, $bet->stake);
        $this->assertSame('1.509', $bet->odds);
        $this->assertSame('2026-08-15 15:41:38', $bet->placedAt->format('Y-m-d H:i:s'));
        $this->assertSame('Sportmarket order 2018198934 · 1 fill: mbook', $bet->notes);
        $this->assertSame(SelectionResult::Lost, $bet->result);
    }

    public function test_first_half_football_is_football_with_a_first_half_market(): void
    {
        $bet = $this->fixtureBet('1972012407');

        $this->assertSame('Football', $bet->sport);
        $this->assertSame('Asian total goals – 1st half', $bet->market);
        $this->assertSame('Over 1.5/2 (Asian, 0-1)', $bet->pick);
    }

    public function test_extra_time_football_is_football_with_an_extra_time_market(): void
    {
        $bet = $this->fixtureBet('1932953388');

        $this->assertSame('Football', $bet->sport);
        $this->assertSame('Match result – extra time', $bet->market);
        $this->assertSame('Dinamo Zagreb', $bet->pick);
        $this->assertSame(35890, $bet->manualPayout); // calculated: 22700 × 1.581 = 35888.7
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fixtureMarkets(): array
    {
        return [
            'ahou' => ['2384017276', 'Asian total goals'],
            'ou' => ['2384015187', 'Total goals'],
            'ah' => ['2057574027', 'Asian handicap'],
            '1x2' => ['2347806017', 'Match result'],
            'ml' => ['2018198934', 'Draw no bet'],
            'extot' => ['2104736726', 'Exact total goals'],
        ];
    }

    #[DataProvider('fixtureMarkets')]
    public function test_market_codes_get_their_names(string $orderId, string $market): void
    {
        $this->assertSame($market, $this->fixtureBet($orderId)->market);
    }

    public function test_the_whole_fixture(): void
    {
        $orders = $this->import()->read($this->fixture(), Currency::Eur);

        $this->assertCount(17, $orders);
        $this->assertSame('2384017276', $orders[0]->orderId); // in the order of the file
        $this->assertSame(
            ['import' => 16, 'skip' => 1],
            array_count_values(array_map(fn (ImportedOrder $order): string => $order->outcome->value, $orders)),
        );

        $bets = array_values(array_filter(array_map(fn (ImportedOrder $order): ?ImportedBet => $order->bet, $orders)));
        $this->assertSame(
            ['won' => 9, 'lost' => 3, 'half_won' => 1, 'half_lost' => 1, 'void' => 2],
            array_count_values(array_map(fn (ImportedBet $bet): string => $bet->result->value, $bets)),
        );
        $this->assertCount(5, array_filter($bets, fn (ImportedBet $bet): bool => $bet->manualPayout !== null));
    }

    public function test_orders_imported_before_are_skipped(): void
    {
        $orders = $this->import()->read($this->fixture(), Currency::Eur, ['sportmarket:2384017276', 'sportmarket:1', '2383987225']);

        $this->assertSame(OrderOutcome::Skip, $orders[0]->outcome);
        $this->assertSame('Imported before.', $orders[0]->reason);
        $this->assertSame(OrderOutcome::Import, $orders[1]->outcome); // a ref without the prefix isn't this order
    }

    public function test_an_order_with_a_fill_that_is_not_settled_is_skipped(): void
    {
        $orders = $this->import()->read($this->csv([
            ['Bet Status' => 'settled'],
            ['Bet Status' => 'open', 'Reconciled P&L (User Currency)' => ''],
            ['Order Id' => '2000', 'Bet Status' => 'failed'],
            ['Order Id' => '2000', 'Bet Status' => 'pending', 'Reconciled P&L (User Currency)' => ''],
        ]), Currency::Eur);

        $this->assertCount(2, $orders);
        $this->assertSame(OrderOutcome::Skip, $orders[0]->outcome);
        $this->assertSame('Not settled yet (a fill is "open"). Import the file again once it is.', $orders[0]->reason);
        $this->assertSame(OrderOutcome::Skip, $orders[1]->outcome);
        $this->assertSame('Not settled yet (a fill is "pending"). Import the file again once it is.', $orders[1]->reason);
    }

    public function test_an_order_in_another_currency_than_the_bookmaker_is_an_error(): void
    {
        $orders = $this->import()->read($this->fixture(), Currency::Dkk);

        $this->assertSame(OrderOutcome::Error, $orders[0]->outcome);
        $this->assertNull($orders[0]->bet);
        $this->assertSame('Line 2: the order is in EUR, but the bookmaker is in DKK.', $orders[0]->reason);
        $this->assertSame(
            ['error' => 16, 'skip' => 1],
            array_count_values(array_map(fn (ImportedOrder $order): string => $order->outcome->value, $orders)),
        );
    }

    public function test_an_order_with_an_unreadable_value_is_an_error_and_the_others_are_imported(): void
    {
        $orders = $this->import()->read($this->csv([
            ['Order Id' => '1', 'Stake (User Currency)' => '100,00'],
            ['Order Id' => '2', 'Price' => '1.0000000000'],
            ['Order Id' => '3', 'Reconciled P&L (User Currency)' => ''],
            ['Order Id' => '4', 'Order Placed' => '21/10/2026 20:26'],
            ['Order Id' => '5', 'Game Date' => '2026-02-30'],
            ['Order Id' => '6', 'Home' => '', 'Away' => ''],
            ['Order Id' => '7', 'Bet Type' => ''],
            ['Order Id' => '8', 'Stake (User Currency)' => '0.00', 'Reconciled P&L (User Currency)' => '0.00'],
            ['Order Id' => '9', 'Sport' => ''],
            ['Order Id' => '10'],
        ]), Currency::Eur);

        $this->assertSame([
            'Line 2: the Stake (User Currency) "100,00" is not an amount.',
            'Line 3: the Price "1.0000000000" is not decimal odds above 1.',
            'Line 4: the Reconciled P&L (User Currency) "" is not an amount.',
            'Line 5: the Order Placed "21/10/2026 20:26" is not a date and time.',
            'Line 6: the Game Date "2026-02-30" is not a date.',
            'Line 7: the order has no Home or Away team.',
            'Line 8: the order has no Bet Type.',
            'The order\'s stake (0.00) can\'t be recorded: it must be above 0 and below 1,000,000,000.',
            'Line 10: the order has no Sport.',
        ], array_map(fn (ImportedOrder $order): ?string => $order->reason, array_slice($orders, 0, 9)));
        $this->assertSame(OrderOutcome::Import, $orders[9]->outcome);
    }

    public function test_a_loss_larger_than_the_stake_is_recorded_as_lost_with_a_warning(): void
    {
        $bet = $this->onlyBet($this->csv([['Reconciled P&L (User Currency)' => '-100.01']]));

        $warning = 'Sportmarket reported a P&L of −100.01 on a stake of 100.00; recorded as lost.';

        $this->assertSame(SelectionResult::Lost, $bet->result);
        $this->assertSame(0, $bet->payout);
        $this->assertNull($bet->manualPayout);
        $this->assertSame($warning, $bet->warning);
        $this->assertSame('Sportmarket order 1000 · 1 fill: fair · '.$warning, $bet->notes);
    }

    public function test_an_order_as_reported_has_no_warning(): void
    {
        $this->assertNull($this->fixtureBet('2383987225')->warning);
    }

    public function test_unknown_sport_and_market_codes_are_used_as_names(): void
    {
        $bet = $this->onlyBet($this->csv([['Sport' => 'basketball', 'Market' => 'spread', 'Competition' => '']]));

        $this->assertSame('basketball', $bet->sport);
        $this->assertSame('spread', $bet->market);
        $this->assertNull($bet->competition);
    }

    public function test_an_order_without_a_market_has_none(): void
    {
        $this->assertNull($this->onlyBet($this->csv([['Market' => '']]))->market);
    }

    public function test_an_order_with_only_one_team_is_named_after_it(): void
    {
        $bet = $this->onlyBet($this->csv([['Home' => 'Arsenal', 'Away' => '']]));

        $this->assertSame(['Arsenal'], $bet->teams);
        $this->assertSame('Arsenal', $bet->eventName);
    }

    public function test_the_settlement_date_is_never_before_the_bet_was_placed(): void
    {
        $bet = $this->onlyBet($this->csv([['Order Placed' => '2026-10-22 00:30:15+02:00', 'Game Date' => '2026-10-20']]));

        $this->assertSame('2026-10-21 22:30:15 UTC', $bet->placedAt->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-10-21 22:30:15 UTC', $bet->settledAt->format('Y-m-d H:i:s e'));
    }

    public function test_a_tie_between_two_results_goes_to_the_first_in_order(): void
    {
        // At odds 3, won pays 300.00 and half won 200.00; 250.00 is just as near to both.
        $bet = $this->onlyBet($this->csv([['Price' => '3', 'Reconciled P&L (User Currency)' => '150.00']]));

        $this->assertSame(SelectionResult::Won, $bet->result);
        $this->assertSame(25000, $bet->manualPayout);
    }

    public function test_columns_are_found_by_name_whatever_their_order(): void
    {
        $columns = array_reverse(array_keys(self::ROW));
        $values = array_map(fn (string $column): string => self::ROW[$column], $columns);
        $csv = "\u{FEFF}".$this->line($columns).$this->line($values);

        $bet = $this->onlyBet($csv);

        $this->assertSame(10000, $bet->stake);
        $this->assertSame('2', $bet->odds);
        $this->assertSame(SelectionResult::Won, $bet->result);
    }

    public function test_the_bet_data_is_a_single_with_its_result(): void
    {
        $data = $this->fixtureBet('2384015187')->betData(7, '1');

        $this->assertSame(7, $data->bookmakerId);
        $this->assertSame(15001, $data->stake);
        $this->assertFalse($data->isFreeBet);
        $this->assertSame(['In-play'], $data->tags);
        $this->assertSame('Sportmarket order 2384015187 · 6 fills: mbook ×4, molly, fair', $data->notes);
        $this->assertCount(1, $data->selections);
        $this->assertSame('1.403', $data->selections[0]->odds);
        $this->assertSame('Bayern München v Arsenal', $data->selections[0]->eventName);
        $this->assertSame(['Bayern München', 'Arsenal'], $data->selections[0]->teams);
        $this->assertNull($data->selections[0]->eventStartsAt);
        $this->assertSame(SelectionResult::Won, $data->selections[0]->result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'empty' => ['', 'The file is empty.'],
            'header only' => ["\"Order Id\",Price\n", 'This is not a Sportmarket orders export: it has no Order Placed, Competition, Game Date, Sport, Home, Away, Bet Type, Bet Status, Bookie, User Currency, Stake (User Currency), Reconciled P&L (User Currency), In Running, Market columns.'],
            'another export' => ["Date,Amount\n2026-10-21,10.00\n", 'This is not a Sportmarket orders export: it has no Order Id, Order Placed, Competition, Game Date, Sport, Home, Away, Bet Type, Bet Status, Bookie, User Currency, Stake (User Currency), Price, Reconciled P&L (User Currency), In Running, Market columns.'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_a_file_that_is_not_an_orders_export_is_refused(string $csv, string $message): void
    {
        $this->expectException(InvalidOrdersFile::class);
        $this->expectExceptionMessage($message);

        $this->import()->read($csv, Currency::Eur);
    }

    public function test_a_file_without_orders_is_refused(): void
    {
        $this->expectException(InvalidOrdersFile::class);
        $this->expectExceptionMessage('The file has no orders.');

        $this->import()->read($this->csv([]), Currency::Eur);
    }

    public function test_a_row_with_too_few_columns_is_refused(): void
    {
        $this->expectException(InvalidOrdersFile::class);
        $this->expectExceptionMessage('Line 3 has 2 columns, but the header has 25.');

        $this->import()->read($this->csv([[]])."1000,fair\n", Currency::Eur);
    }

    public function test_a_row_without_an_order_id_is_refused(): void
    {
        $this->expectException(InvalidOrdersFile::class);
        $this->expectExceptionMessage('Line 2 has no Order Id.');

        $this->import()->read($this->csv([['Order Id' => '']]), Currency::Eur);
    }

    public function test_blank_lines_are_ignored(): void
    {
        $orders = $this->import()->read($this->csv([[]])."\n\n", Currency::Eur);

        $this->assertCount(1, $orders);
    }

    private function import(): SportmarketImport
    {
        return new SportmarketImport(new SettlementCalculator);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    private function fixtureOrder(string $orderId): ImportedOrder
    {
        foreach ($this->import()->read($this->fixture(), Currency::Eur) as $order) {
            if ($order->orderId === $orderId) {
                return $order;
            }
        }

        $this->fail("Order {$orderId} is not in the fixture.");
    }

    private function fixtureBet(string $orderId): ImportedBet
    {
        $order = $this->fixtureOrder($orderId);
        $this->assertSame(OrderOutcome::Import, $order->outcome, (string) $order->reason);

        return $order->bet ?? $this->fail('An order to import has a bet.');
    }

    private function onlyBet(string $csv): ImportedBet
    {
        $orders = $this->import()->read($csv, Currency::Eur);
        $this->assertCount(1, $orders);
        $this->assertSame(OrderOutcome::Import, $orders[0]->outcome, (string) $orders[0]->reason);

        return $orders[0]->bet ?? $this->fail('An order to import has a bet.');
    }

    /**
     * An export with the header and a row per item, each a settled single fill with the
     * given columns changed.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $csv = $this->line(array_keys(self::ROW));

        foreach ($rows as $row) {
            $csv .= $this->line(array_values([...self::ROW, ...$row]));
        }

        return $csv;
    }

    /**
     * @param  list<string>  $values
     */
    private function line(array $values): string
    {
        return implode(',', array_map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"', $values))."\n";
    }
}
