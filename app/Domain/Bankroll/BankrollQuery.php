<?php

namespace App\Domain\Bankroll;

use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\TransactionType;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\User;

/**
 * Load a user's bankroll: their bookmakers (active first, then by name), the sums of
 * their transactions per bookmaker (aggregated in SQL) and their bets (one query, plus
 * one for the selections the bet factor needs). Only the user's own data is ever read.
 */
final class BankrollQuery
{
    public function __construct(
        private BankrollCalculator $calculator,
        private SettlementCalculator $settlement,
    ) {}

    public function bankroll(User $user): Bankroll
    {
        return $this->calculator->calculate($this->accounts($user), $this->bets($user), $user->base_currency);
    }

    /**
     * @return list<BookmakerAccount>
     */
    private function accounts(User $user): array
    {
        /** @var array<int, array{deposits: int, withdrawals: int, transactions: int}> $sums */
        $sums = [];

        $rows = $user->bankrollTransactions()->getQuery()->toBase()
            ->selectRaw('bookmaker_id, type, sum(amount) as total, count(*) as transactions')
            ->groupBy('bookmaker_id', 'type')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->bookmaker_id;
            $key = $row->type === TransactionType::Deposit->value ? 'deposits' : 'withdrawals';
            $sums[$id] ??= ['deposits' => 0, 'withdrawals' => 0, 'transactions' => 0];
            $sums[$id][$key] += (int) $row->total;
            $sums[$id]['transactions'] += (int) $row->transactions;
        }

        $bookmakers = $user->bookmakers()->orderByDesc('is_active')->orderBy('name')->get();

        return array_values($bookmakers->map(fn (Bookmaker $bookmaker): BookmakerAccount => new BookmakerAccount(
            id: $bookmaker->id,
            name: $bookmaker->name,
            currency: $bookmaker->currency,
            exchangeRate: $bookmaker->exchange_rate,
            isActive: $bookmaker->is_active,
            deposits: $sums[$bookmaker->id]['deposits'] ?? 0,
            withdrawals: $sums[$bookmaker->id]['withdrawals'] ?? 0,
            transactions: $sums[$bookmaker->id]['transactions'] ?? 0,
        ))->all());
    }

    /**
     * @return list<BankrollBet>
     */
    private function bets(User $user): array
    {
        $bets = $user->bets()
            ->with('selections:id,bet_id,odds,result')
            ->get(['id', 'bookmaker_id', 'type', 'stake', 'currency', 'exchange_rate', 'is_free_bet', 'total_odds', 'status', 'settled_at', 'profit']);

        return array_values($bets->map(function (Bet $bet): BankrollBet {
            $settled = $bet->isSettled();
            $legs = array_values($bet->selections->map(fn (Selection $selection): Leg => new Leg($selection->odds, $selection->result))->all());

            return new BankrollBet(
                bookmakerId: $bet->bookmaker_id,
                currency: $bet->currency,
                stake: $bet->stake,
                profit: $settled ? $bet->profit : null,
                factor: $settled ? $this->settlement->factor($legs) : null,
                exchangeRate: $bet->exchange_rate,
                type: $bet->type,
                isFreeBet: $bet->is_free_bet,
                totalOdds: $bet->total_odds,
                settledAt: $settled ? $bet->settled_at?->toImmutable() : null,
            );
        })->all());
    }
}
