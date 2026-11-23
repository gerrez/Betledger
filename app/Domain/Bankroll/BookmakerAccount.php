<?php

namespace App\Domain\Bankroll;

use App\Domain\Currency;

/**
 * A bookmaker with the sums of its deposits and withdrawals, in minor units of its
 * currency (transactions are always in the bookmaker's currency).
 */
final readonly class BookmakerAccount
{
    /**
     * @param  string  $exchangeRate  the bookmaker's current rate: 1 unit of its currency = this many units of the base currency
     * @param  int  $transactions  how many deposits and withdrawals it has
     */
    public function __construct(
        public int $id,
        public string $name,
        public Currency $currency,
        public string $exchangeRate,
        public bool $isActive,
        public int $deposits = 0,
        public int $withdrawals = 0,
        public int $transactions = 0,
    ) {}
}
