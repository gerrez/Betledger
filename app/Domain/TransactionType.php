<?php

namespace App\Domain;

/**
 * A movement of the user's money to or from a bookmaker account (see "Bankroll" in
 * docs/spec.md). The amount is always positive; the type says which way it went.
 */
enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
        };
    }
}
