<?php

namespace App\Domain\Bankroll;

use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\Totals;

/**
 * Bankroll rules from docs/spec.md: per bookmaker in its own currency, and a total in the
 * base currency. The bet figures (bets, open stake, turnover, profit, ROI) come from
 * StatisticsCalculator::totals(), so they follow the statistics definitions.
 */
final class BankrollCalculator
{
    public function __construct(private StatisticsCalculator $statistics) {}

    /**
     * @param  list<BookmakerAccount>  $accounts  in the order to show them
     * @param  list<BankrollBet>  $bets  the user's bets
     */
    public function calculate(array $accounts, array $bets, Currency $baseCurrency): Bankroll
    {
        /** @var array<int, list<BankrollBet>> $betsByBookmaker */
        $betsByBookmaker = [];

        foreach ($bets as $bet) {
            $betsByBookmaker[$bet->bookmakerId][] = $bet;
        }

        $rows = [];
        $listedBets = [];
        $balance = 0;

        foreach ($accounts as $account) {
            $accountBets = $betsByBookmaker[$account->id] ?? [];

            // Active bookmakers are always listed, so the user can deposit; inactive ones
            // only when they have something to show.
            if (! $account->isActive && $accountBets === [] && $account->transactions === 0) {
                continue;
            }

            $row = $this->row($account, $accountBets, $baseCurrency);
            $rows[] = $row;
            $balance += $row->balanceInBase;
            array_push($listedBets, ...$accountBets);
        }

        $totals = $this->statistics->totals(array_map(fn (BankrollBet $bet): BetFigures => $bet->inBaseCurrency, $listedBets));

        return new Bankroll($rows, $this->figures($baseCurrency, $totals, $balance));
    }

    /**
     * @param  list<BankrollBet>  $bets  the bookmaker's bets
     */
    private function row(BookmakerAccount $account, array $bets, Currency $baseCurrency): BookmakerBankroll
    {
        // Bets saved before the bookmaker's currency was locked may be in another currency;
        // such a row can only be added up in the base currency.
        $inBaseCurrency = array_filter($bets, fn (BankrollBet $bet): bool => $bet->currency !== $account->currency) !== [];

        if ($inBaseCurrency) {
            $totals = $this->statistics->totals(array_map(fn (BankrollBet $bet): BetFigures => $bet->inBaseCurrency, $bets));
            $deposits = Money::convert($account->deposits, $account->exchangeRate);
            $withdrawals = Money::convert($account->withdrawals, $account->exchangeRate);
        } else {
            $totals = $this->statistics->totals(array_map(fn (BankrollBet $bet): BetFigures => $bet->inOwnCurrency, $bets));
            $deposits = $account->deposits;
            $withdrawals = $account->withdrawals;
        }

        $balance = $deposits - $withdrawals + $totals->profit - $totals->openStake;

        return new BookmakerBankroll(
            bookmakerId: $account->id,
            name: $account->name,
            isActive: $account->isActive,
            figures: $this->figures($inBaseCurrency ? $baseCurrency : $account->currency, $totals, $balance),
            deposits: $deposits,
            withdrawals: $withdrawals,
            inBaseCurrency: $inBaseCurrency,
            balanceInBase: $inBaseCurrency ? $balance : Money::convert($balance, $account->exchangeRate),
        );
    }

    private function figures(Currency $currency, Totals $totals, int $balance): BankrollFigures
    {
        return new BankrollFigures(
            currency: $currency,
            bets: $totals->settledBets + $totals->openBets,
            openBets: $totals->openBets,
            openStake: $totals->openStake,
            turnover: $totals->turnover,
            profit: $totals->profit,
            roi: $totals->roi,
            balance: $balance,
        );
    }
}
