<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Currency and time zone" subheading="The currency your statistics are shown in, and the time zone your times are in.">
        <form wire:submit="updateBaseCurrency" class="my-6 w-full space-y-6">
            <flux:text>
                Statistics are shown in your base currency. Bets in other currencies are converted
                with the exchange rate saved on each bet.
            </flux:text>

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
                    exchange rate converts to the base currency it was saved with. Recently deleted bets
                    count too, until they are deleted for good in Settings → Deleted bets.
                </flux:text>
            @endif
        </form>

        <flux:separator variant="subtle" />

        <form wire:submit="updateTimezone" class="my-6 w-full space-y-6">
            <x-select wire:model="timezone" label="Time zone">
                @foreach ($timezones as $option)
                    <x-select.option :value="$option['value']">{{ $option['label'] }}</x-select.option>
                @endforeach
            </x-select>

            <flux:text>
                Times are entered and shown in this time zone, and statistics group bets into
                days and months by it. Changing it doesn’t move your bets: each keeps the moment
                it was recorded at.
            </flux:text>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>

                <x-action-message class="me-3" on="timezone-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
