<?php

namespace Tests\Feature\Bets;

use App\Domain\Bets\SettleBet;
use App\Domain\BetStatus;
use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Selection;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SettleBetTest extends TestCase
{
    use RefreshDatabase;

    private SettleBet $settleBet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settleBet = app(SettleBet::class);
        $this->freezeTime();
    }

    public function test_the_result_of_a_single_settles_it_now(): void
    {
        $bet = Bet::factory()->single('2.10')->create(['stake' => 10000]);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::Won);

        $this->assertSettlement($bet, BetStatus::Settled, 21000, 11000, now());
    }

    public function test_a_half_won_single(): void
    {
        $bet = Bet::factory()->single('1.95')->create(['stake' => 10000]);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::HalfWon);

        $this->assertSettlement($bet, BetStatus::Settled, 14750, 4750, now());
    }

    public function test_an_accumulator_stays_open_until_every_selection_has_a_result(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00', '1.80'])->create(['stake' => 10000]);

        $this->settleBet->setResult($this->selection($bet, 1), SelectionResult::Won);
        $this->settleBet->setResult($this->selection($bet, 2), SelectionResult::Void);

        $this->assertSettlement($bet, BetStatus::Open, null, null, null);

        $this->settleBet->setResult($this->selection($bet, 3), SelectionResult::Won);

        $this->assertSettlement($bet, BetStatus::Settled, 27000, 17000, now());
    }

    public function test_a_lost_selection_settles_an_accumulator_while_others_are_pending(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00', '1.80'])->create(['stake' => 10000]);
        $lostAt = now();

        $this->settleBet->setResult($this->selection($bet, 2), SelectionResult::Lost);

        $this->assertSettlement($bet, BetStatus::Settled, 0, -10000, $lostAt);

        $this->travel(2)->days();
        $this->settleBet->setResult($this->selection($bet, 1), SelectionResult::Won);

        $this->assertSettlement($bet, BetStatus::Settled, 0, -10000, $lostAt);
    }

    public function test_clearing_a_result_makes_the_bet_open_again(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create();

        $this->settleBet->setResult($this->selection($bet), null);

        $this->assertSettlement($bet, BetStatus::Open, null, null, null);
        $this->assertNull($this->selection($bet)->result);
    }

    public function test_clearing_the_lost_selection_of_an_accumulator_reopens_it(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00'])->withResults(SelectionResult::Lost, null)->create();

        $this->settleBet->setResult($this->selection($bet, 1), null);

        $this->assertSettlement($bet, BetStatus::Open, null, null, null);
    }

    public function test_changing_a_result_settles_the_bet_again_and_keeps_its_settlement_date(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 10000]);
        $settledAt = $bet->settled_at;

        $this->travel(1)->day();
        $this->settleBet->setResult($this->selection($bet), SelectionResult::Void);

        $this->assertSettlement($bet, BetStatus::Settled, 10000, 0, $settledAt);
    }

    public function test_clearing_every_result_unsettles_the_bet(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00'])->settled(SelectionResult::Won)->create();

        $this->settleBet->clearResults($bet);

        $this->assertSettlement($bet, BetStatus::Open, null, null, null);
        $this->assertSame(0, $bet->selections()->whereNotNull('result')->count());
    }

    public function test_a_won_free_bet_pays_only_the_winnings(): void
    {
        $bet = Bet::factory()->single('3.00')->freeBet()->create(['stake' => 10000]);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::Won);

        $this->assertSettlement($bet, BetStatus::Settled, 20000, 20000, now());
    }

    public function test_a_voided_free_bet_has_no_profit(): void
    {
        $bet = Bet::factory()->single('3.00')->freeBet()->create(['stake' => 10000]);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::Void);

        $this->assertSettlement($bet, BetStatus::Settled, 0, 0, now());
    }

    public function test_the_payout_of_a_settled_bet_can_be_replaced_by_the_amount_paid(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 10000]);

        $this->settleBet->overridePayout($bet, 20000);

        $bet->refresh();
        $this->assertSame(20000, $bet->payout);
        $this->assertSame(10000, $bet->profit);
        $this->assertTrue($bet->payout_is_manual);
        $this->assertSame(BetStatus::Settled, $bet->status);
    }

    public function test_a_manual_payout_on_a_free_bet_is_its_profit(): void
    {
        $bet = Bet::factory()->single('3.00')->freeBet()->settled(SelectionResult::Won)->create(['stake' => 10000]);

        $this->settleBet->overridePayout($bet, 15000);

        $this->assertSame(15000, $bet->refresh()->profit);
    }

    public function test_an_open_bet_cannot_have_a_manual_payout(): void
    {
        $bet = Bet::factory()->single()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->settleBet->overridePayout($bet, 1000);
    }

    public function test_a_manual_payout_cannot_be_negative(): void
    {
        $bet = Bet::factory()->single()->settled()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->settleBet->overridePayout($bet, -1);
    }

    public function test_changing_a_result_discards_the_manual_payout(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 10000]);
        $this->settleBet->overridePayout($bet, 20000);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::HalfWon);

        $bet->refresh();
        $this->assertFalse($bet->payout_is_manual);
        $this->assertSame(15500, $bet->payout);
        $this->assertSame(5500, $bet->profit);
    }

    public function test_setting_the_result_a_selection_already_has_keeps_the_manual_payout(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 10000]);
        $this->settleBet->overridePayout($bet, 20000);

        $this->settleBet->setResult($this->selection($bet), SelectionResult::Won);

        $bet->refresh();
        $this->assertTrue($bet->payout_is_manual);
        $this->assertSame(20000, $bet->payout);
    }

    public function test_a_manual_payout_can_be_discarded(): void
    {
        $bet = Bet::factory()->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 10000]);
        $settledAt = $bet->settled_at;
        $this->settleBet->overridePayout($bet, 20000);

        $this->settleBet->discardManualPayout($bet);

        $this->assertSettlement($bet, BetStatus::Settled, 21000, 11000, $settledAt);
        $this->assertFalse($bet->refresh()->payout_is_manual);
    }

    private function selection(Bet $bet, int $position = 1): Selection
    {
        return $bet->selections()->where('position', $position)->sole();
    }

    private function assertSettlement(Bet $bet, BetStatus $status, ?int $payout, ?int $profit, ?CarbonInterface $settledAt): void
    {
        $bet->refresh();

        $this->assertSame($status, $bet->status);
        $this->assertSame($payout, $bet->payout);
        $this->assertSame($profit, $bet->profit);
        $this->assertSame($settledAt?->toDateTimeString(), $bet->settled_at?->toDateTimeString());
    }
}
