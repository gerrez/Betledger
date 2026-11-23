<?php

namespace App\Domain\Bankroll;

use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\ExchangeRate;
use App\Domain\Statistics\BetFigures;

/**
 * A bet as far as the bankroll is concerned: its figures in its own currency (for the
 * bookmaker's row) and in the base currency with the bet's own rate (for the total, as
 * statistics count it).
 */
final readonly class BankrollBet
{
    public BetFigures $inOwnCurrency;

    public BetFigures $inBaseCurrency;

    /**
     * @param  int  $stake  minor units of the bet's currency
     * @param  int|null  $profit  minor units of the bet's currency (the stored profit), null while open
     * @param  string|null  $factor  the bet factor by the settlement rules, null while open
     * @param  string  $exchangeRate  the bet's own rate to the base currency
     */
    public function __construct(
        public int $bookmakerId,
        public Currency $currency,
        int $stake,
        ?int $profit,
        ?string $factor,
        string $exchangeRate,
        BetType $type,
        bool $isFreeBet,
        string $totalOdds,
    ) {
        $this->inOwnCurrency = new BetFigures($stake, $profit, $factor, ExchangeRate::SAME_CURRENCY, $type, $isFreeBet, $totalOdds);
        $this->inBaseCurrency = new BetFigures($stake, $profit, $factor, $exchangeRate, $type, $isFreeBet, $totalOdds);
    }
}
