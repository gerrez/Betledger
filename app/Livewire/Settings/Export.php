<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Explains the CSV export and links to the download (ExportBetsController).
 */
#[Title('Export')]
class Export extends Component
{
    public function render(): View
    {
        return view('livewire.settings.export', [
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
