<?php

namespace App\Policies;

use App\Models\BankrollTransaction;
use App\Models\Bookmaker;
use App\Models\User;

class BankrollTransactionPolicy
{
    /**
     * Determine whether the user can add a transaction at the bookmaker: only at one of
     * their own active bookmakers.
     */
    public function create(User $user, Bookmaker $bookmaker): bool
    {
        return $user->id === $bookmaker->user_id && $bookmaker->is_active;
    }

    /**
     * Determine whether the user can edit the transaction (also after its bookmaker was
     * deactivated).
     */
    public function update(User $user, BankrollTransaction $transaction): bool
    {
        return $user->id === $transaction->user_id;
    }

    /**
     * Determine whether the user can delete the transaction.
     */
    public function delete(User $user, BankrollTransaction $transaction): bool
    {
        return $user->id === $transaction->user_id;
    }
}
