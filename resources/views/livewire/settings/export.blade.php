<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Export" subheading="Download all of your bets as a CSV file, e.g. as a backup or to open in a spreadsheet.">
        <div class="my-6 w-full space-y-6">
            <flux:text>
                The file has one row per selection, so an accumulator takes several rows with the bet’s details repeated; <code>bet_id</code> groups them.
                Amounts are in major units (12.50) next to their currency code, odds as decimal odds, and dates and times in UTC.
            </flux:text>

            @if ($betCount === 0)
                <flux:text>You have no bets yet, so there is nothing to export. The file only has the column names.</flux:text>
            @else
                <flux:text>{{ $betCount === 1 ? 'You have 1 bet.' : "You have {$betCount} bets." }}</flux:text>
            @endif

            {{-- A plain link (no wire:navigate): the browser downloads the file. --}}
            <flux:button variant="primary" icon="arrow-down-tray" :href="route('settings.export.download')">Download CSV</flux:button>
        </div>
    </x-settings.layout>
</section>
