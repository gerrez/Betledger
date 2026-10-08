<?php

use App\Domain\Currency;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Currency')] class extends Component {
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
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Currency" subheading="Statistics are shown in your base currency. Bets in other currencies are converted with the exchange rate saved on each bet.">
        <form wire:submit="updateBaseCurrency" class="my-6 w-full space-y-6">
            <flux:select wire:model="base_currency" label="Base currency">
                @foreach (Currency::cases() as $currency)
                    <flux:select.option :value="$currency->value">{{ $currency->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:text>
                Bookmakers in your base currency always use a rate of 1. If you change it, check
                the exchange rates on the <flux:link :href="route('bookmakers.index')" wire:navigate>Bookmakers</flux:link> page.
            </flux:text>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>

                <x-action-message class="me-3" on="base-currency-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
