<?php

namespace App\Domain\Bankroll;

/**
 * One bookmaker's row of the bankroll: its figures in its own currency, or in the base
 * currency when its bets are in another currency than its own (`$inBaseCurrency`).
 */
final readonly class BookmakerBankroll
{
    public function __construct(
        public int $bookmakerId,
        public string $name,
        public bool $isActive,
        public BankrollFigures $figures,
        /** Sum of deposits, in the figures' currency. */
        public int $deposits,
        /** Sum of withdrawals, in the figures' currency. */
        public int $withdrawals,
        /** Whether the row is shown in the base currency instead of the bookmaker's own. */
        public bool $inBaseCurrency,
        /** The balance in the base currency, as it counts in the total balance. */
        public int $balanceInBase,
    ) {}

    public function hasActivity(): bool
    {
        return $this->figures->bets > 0 || $this->deposits > 0 || $this->withdrawals > 0;
    }
}
