<?php

namespace Tests\Unit\Domain\Bankroll;

use App\Domain\Bankroll\Bankroll;
use App\Domain\Bankroll\BankrollBet;
use App\Domain\Bankroll\BankrollCalculator;
use App\Domain\Bankroll\BookmakerAccount;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Statistics\StatisticsCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BankrollCalculatorTest extends TestCase
{
    public function test_deposits_and_withdrawals_only_500_minus_120_is_380(): void
    {
        $row = $this->calculate([$this->account(1, deposits: 50000, withdrawals: 12000, transactions: 3)])->rows[0];

        $this->assertSame(50000, $row->deposits);
        $this->assertSame(12000, $row->withdrawals);
        $this->assertSame(38000, $row->figures->balance);
        $this->assertSame(0, $row->figures->bets);
        $this->assertSame(0, $row->figures->turnover);
        $this->assertSame(0, $row->figures->profit);
        $this->assertNull($row->figures->roi);
        $this->assertTrue($row->hasActivity());
    }

    /**
     * Won 10 @ 2.10 (+11), lost 20 (−20), void 5 (0), half won 10 @ 1.90 (+4.50) and a
     * manual payout of 100 on 50 (+50): turnover 95, profit 45.50, ROI 45.5 / 95 =
     * 0.4789…, balance 100 + 45.50 = 145.50.
     */
    public function test_settled_bets_add_their_stored_profit(): void
    {
        $row = $this->calculate([$this->account(1, deposits: 10000, transactions: 1)], [
            $this->settled(1, 1000, 1100, factor: '2.1'),
            $this->settled(1, 2000, -2000, factor: '0'),
            $this->settled(1, 500, 0, factor: '1'),
            $this->settled(1, 1000, 450, factor: '1.45'),
            $this->settled(1, 5000, 5000, factor: '2.05'),
        ])->rows[0];

        $this->assertSame(5, $row->figures->bets);
        $this->assertSame(0, $row->figures->openBets);
        $this->assertSame(9500, $row->figures->turnover);
        $this->assertSame(4550, $row->figures->profit);
        $this->assertSame('0.4789473684', $row->figures->roi);
        $this->assertSame(14550, $row->figures->balance);
    }

    /**
     * Deposit 100, open cash bet 25, open free bet 10: balance 100 − 25 = 75.
     */
    public function test_an_open_cash_bet_reduces_the_balance_and_an_open_free_bet_does_not(): void
    {
        $row = $this->calculate([$this->account(1, deposits: 10000, transactions: 1)], [
            $this->open(1, 2500),
            $this->open(1, 1000, isFreeBet: true),
        ])->rows[0];

        $this->assertSame(2, $row->figures->bets);
        $this->assertSame(2, $row->figures->openBets);
        $this->assertSame(2500, $row->figures->openStake);
        $this->assertSame(7500, $row->figures->balance);
    }

    /**
     * A free bet of 10 won at 3.00 pays its winnings, 20, which is all profit; its stake
     * is not turnover.
     */
    public function test_a_settled_free_bets_profit_is_added(): void
    {
        $row = $this->calculate([$this->account(1)], [
            $this->settled(1, 1000, 2000, factor: '3', isFreeBet: true),
        ])->rows[0];

        $this->assertSame(2000, $row->figures->profit);
        $this->assertSame(0, $row->figures->turnover);
        $this->assertNull($row->figures->roi);
        $this->assertSame(2000, $row->figures->balance);
    }

    public function test_a_bookmaker_without_activity_shows_zeros(): void
    {
        $bankroll = $this->calculate([$this->account(1)]);
        $row = $bankroll->rows[0];

        $this->assertFalse($row->hasActivity());
        $this->assertFalse($bankroll->hasActivity());
        $this->assertSame([0, 0, 0, 0, 0, 0], [$row->figures->bets, $row->figures->openStake, $row->figures->turnover, $row->figures->profit, $row->figures->balance, $row->deposits]);
        $this->assertNull($row->figures->roi);
        $this->assertSame(0, $bankroll->total->balance);
    }

    public function test_inactive_bookmakers_are_listed_only_with_bets_or_transactions(): void
    {
        $bankroll = $this->calculate([
            $this->account(1, isActive: false),
            $this->account(2, isActive: false, deposits: 1000, transactions: 1),
            $this->account(3, isActive: false),
        ], [$this->open(3, 500)]);

        $this->assertSame([2, 3], array_map(fn ($row): int => $row->bookmakerId, $bankroll->rows));
    }

    /**
     * Base EUR.
     * - Unibet (EUR): deposit 100, won 20 → +22: balance 122.00 EUR.
     * - Bet365 (GBP, now 1.20): deposit £50, lost £10 placed at 1.15: balance £40 →
     *   €48.00 at today's rate.
     * - Danske Spil (DKK, now 0.1345): withdrawal 10 kr: balance −10.00 kr →
     *   −1000 øre × 0.1345 = −134.5 cents → −€1.35 (half away from zero).
     * Total balance 122.00 + 48.00 − 1.35 = €168.65. Bet figures with each bet's own rate:
     * turnover 20 + 11.50 = 31.50, profit 22 − 11.50 = 10.50, ROI 10.5 / 31.5 = 0.3333….
     */
    public function test_the_total_converts_bets_with_their_own_rate_and_balances_with_the_current_rate(): void
    {
        $bankroll = $this->calculate([
            $this->account(1, deposits: 10000, transactions: 1),
            $this->account(2, Currency::Gbp, '1.20', deposits: 5000, transactions: 1),
            $this->account(3, Currency::Dkk, '0.1345', withdrawals: 1000, transactions: 1),
        ], [
            $this->settled(1, 2000, 2200, factor: '2.1'),
            $this->settled(2, 1000, -1000, factor: '0', currency: Currency::Gbp, rate: '1.15'),
        ]);

        [$unibet, $bet365, $danskeSpil] = $bankroll->rows;

        $this->assertSame([12200, Currency::Eur, 12200], [$unibet->figures->balance, $unibet->figures->currency, $unibet->balanceInBase]);
        $this->assertSame([4000, Currency::Gbp, 4800], [$bet365->figures->balance, $bet365->figures->currency, $bet365->balanceInBase]);
        $this->assertSame([1000, -1000], [$bet365->figures->turnover, $bet365->figures->profit]);
        $this->assertSame([-1000, Currency::Dkk, -135], [$danskeSpil->figures->balance, $danskeSpil->figures->currency, $danskeSpil->balanceInBase]);

        $total = $bankroll->total;
        $this->assertSame(Currency::Eur, $total->currency);
        $this->assertSame(16865, $total->balance);
        $this->assertSame(2, $total->bets);
        $this->assertSame(3150, $total->turnover);
        $this->assertSame(1050, $total->profit);
        $this->assertSame('0.3333333333', $total->roi);
        $this->assertSame(0, $total->openStake);
    }

    /**
     * Bet365 is in GBP (now 1.20) but has a bet in EUR from before the lock, so its row is
     * in EUR: won £10 at 1.15 (+€11.50 turnover, +€11.50 profit), open €20, deposit £50 →
     * €60. Balance 60 + 11.50 − 20 = €51.50, counted as is in the total.
     */
    public function test_a_bookmaker_with_bets_in_more_than_one_currency_is_shown_in_the_base_currency(): void
    {
        $bankroll = $this->calculate([$this->account(2, Currency::Gbp, '1.20', deposits: 5000, transactions: 1)], [
            $this->settled(2, 1000, 1000, factor: '2', currency: Currency::Gbp, rate: '1.15'),
            $this->open(2, 2000),
        ]);
        $row = $bankroll->rows[0];

        $this->assertTrue($row->inBaseCurrency);
        $this->assertSame(Currency::Eur, $row->figures->currency);
        $this->assertSame(6000, $row->deposits);
        $this->assertSame(1150, $row->figures->turnover);
        $this->assertSame(1150, $row->figures->profit);
        $this->assertSame(2000, $row->figures->openStake);
        $this->assertSame(5150, $row->figures->balance);
        $this->assertSame(5150, $row->balanceInBase);
        $this->assertSame(5150, $bankroll->total->balance);
    }

    /**
     * @param  list<BookmakerAccount>  $accounts
     * @param  list<BankrollBet>  $bets
     */
    private function calculate(array $accounts, array $bets = []): Bankroll
    {
        return (new BankrollCalculator(new StatisticsCalculator))->calculate($accounts, $bets, Currency::Eur);
    }

    private function account(int $id, Currency $currency = Currency::Eur, string $rate = '1', bool $isActive = true, int $deposits = 0, int $withdrawals = 0, int $transactions = 0): BookmakerAccount
    {
        return new BookmakerAccount($id, "Bookmaker {$id}", $currency, $rate, $isActive, $deposits, $withdrawals, $transactions);
    }

    private function settled(int $bookmakerId, int $stake, int $profit, string $factor, bool $isFreeBet = false, Currency $currency = Currency::Eur, string $rate = '1'): BankrollBet
    {
        return new BankrollBet($bookmakerId, $currency, $stake, $profit, $factor, $rate, BetType::Single, $isFreeBet, '2.1000', CarbonImmutable::parse('2026-11-01 20:00'));
    }

    private function open(int $bookmakerId, int $stake, bool $isFreeBet = false, Currency $currency = Currency::Eur, string $rate = '1'): BankrollBet
    {
        return new BankrollBet($bookmakerId, $currency, $stake, null, null, $rate, BetType::Single, $isFreeBet, '2.1000', null);
    }
}
