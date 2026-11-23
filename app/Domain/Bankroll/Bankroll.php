<?php

namespace App\Domain\Bankroll;

/**
 * The user's bankroll: one row per bookmaker listed, and the total in the base currency.
 */
final readonly class Bankroll
{
    /**
     * @param  list<BookmakerBankroll>  $rows
     */
    public function __construct(
        public array $rows,
        public BankrollFigures $total,
    ) {}

    /**
     * Whether any bookmaker has bets or transactions.
     */
    public function hasActivity(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->hasActivity()) {
                return true;
            }
        }

        return false;
    }
}
