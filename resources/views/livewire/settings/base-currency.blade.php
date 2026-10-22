<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Currency" subheading="Statistics are shown in your base currency. Bets in other currencies are converted with the exchange rate saved on each bet.">
        <form wire:submit="updateBaseCurrency" class="my-6 w-full space-y-6">
            <x-select wire:model="base_currency" label="Base currency" :disabled="! $canChange">
                @foreach ($currencies as $currency)
                    <x-select.option :value="$currency->value">{{ $currency->label() }}</x-select.option>
                @endforeach
            </x-select>

            @if ($canChange)
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
            @else
                <flux:text>
                    The base currency can’t be changed once you have recorded bets: each bet’s
                    exchange rate converts to the base currency it was saved with.
                </flux:text>
            @endif
        </form>
    </x-settings.layout>
</section>
