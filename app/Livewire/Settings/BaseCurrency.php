<?php

namespace App\Livewire\Settings;

use App\Domain\Currency;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Currency')]
class BaseCurrency extends Component
{
    public string $base_currency = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->base_currency = Auth::user()->base_currency->value;
    }

    /**
     * Change the currency that statistics are shown in.
     */
    public function updateBaseCurrency(): void
    {
        $validated = $this->validate([
            'base_currency' => ['required', Rule::enum(Currency::class)],
        ]);

        Auth::user()->changeBaseCurrency(Currency::from($validated['base_currency']));

        $this->dispatch('base-currency-updated');
    }

    public function render(): View
    {
        return view('livewire.settings.base-currency', [
            'currencies' => Currency::cases(),
        ]);
    }
}
