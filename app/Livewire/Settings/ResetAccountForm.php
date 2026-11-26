<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Deletes all of the user's bets (with their selections and tags) and keeps the account,
 * bookmakers, lists, bankroll transactions and settings.
 */
class ResetAccountForm extends Component
{
    public string $password = '';

    public function resetAccount(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = $this->user();

        // Selections, their teams and the bet's tags go with it (cascading foreign keys).
        DB::transaction(fn () => $user->bets()->delete());

        $this->reset('password');

        Flux::modal('confirm-account-reset')->close();

        $this->dispatch('account-reset');
    }

    public function render(): View
    {
        return view('livewire.settings.reset-account-form', [
            'betCount' => $this->user()->bets()->count(),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
