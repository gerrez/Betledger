<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Import" subheading="Add your settled bets from a Sportmarket orders export (Orders → CSV). You see every order before anything is saved.">
        @if ($bookmakers->isEmpty())
            <flux:text class="my-6">
                Add the bookmaker to import the bets to on the
                <flux:link :href="route('bookmakers.index')" wire:navigate>Bookmakers</flux:link> page first.
            </flux:text>
        @elseif (! $previewing)
            <form wire:submit="preview" class="my-6 w-full space-y-6">
                <x-select wire:model.live="bookmaker" label="Bookmaker">
                    @foreach ($bookmakers as $option)
                        <x-select.option :value="(string) $option->id">{{ $option->name }} ({{ $option->currency->value }})</x-select.option>
                    @endforeach
                </x-select>

                <flux:input type="file" wire:model="file" label="Sportmarket orders export" accept=".csv" description:trailing="The bookmaker must be in the file’s currency. Orders imported before are skipped, so you can import a newer export of the same orders." />

                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="file">Preview</flux:button>
            </form>
        @else
            <div class="my-6 w-full space-y-6">
                <flux:text>{{ $summary }} Nothing is saved until you import.</flux:text>

                @if ($errorOrders !== [])
                    <section>
                        <flux:heading>Errors · {{ count($errorOrders) }}</flux:heading>
                        <flux:text class="mt-1">These orders can’t be imported as they are. The other orders are imported without them.</flux:text>

                        <ul class="mt-3 divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
                            @foreach ($errorOrders as $order)
                                <li wire:key="error-{{ $order->orderId }}" class="px-5 py-3">
                                    <p class="text-sm font-medium tabular-nums">Order {{ $order->orderId }}</p>
                                    <p class="mt-0.5 text-sm text-loss">{{ $order->reason }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($bets !== [])
                    <section>
                        <flux:heading>To import · {{ count($bets) }}</flux:heading>

                        <ul class="mt-3 divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
                            @foreach ($bets as $index => $bet)
                                <li wire:key="bet-{{ $index }}" class="flex items-start justify-between gap-4 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium">{{ $bet['event'] }}</p>
                                        <p class="mt-0.5 text-sm text-muted tabular-nums">{{ $bet['pick'] }}</p>
                                        <p class="mt-0.5 text-xs text-muted tabular-nums">{{ $bet['placed'] }} · stake {{ $bet['stake'] }}</p>
                                    </div>

                                    <div class="shrink-0 text-end tabular-nums">
                                        <p class="text-sm font-medium">{{ $bet['result'] }}</p>
                                        <p @class(['text-sm', 'text-profit' => $bet['tone'] === 'profit', 'text-loss' => $bet['tone'] === 'loss', 'text-muted' => $bet['tone'] === 'none'])>{{ $bet['profit'] }}</p>

                                        @if ($bet['isManual'])
                                            <p class="text-xs text-muted">payout as reported</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($skippedOrders !== [])
                    <section>
                        <flux:heading>Skipped · {{ count($skippedOrders) }}</flux:heading>

                        <ul class="mt-3 divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
                            @foreach ($skippedOrders as $order)
                                <li wire:key="skipped-{{ $order->orderId }}" class="px-5 py-3">
                                    <p class="text-sm font-medium tabular-nums">Order {{ $order->orderId }}</p>
                                    <p class="mt-0.5 text-sm text-muted">{{ $order->reason }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <flux:error name="file" />

                <div class="flex flex-wrap items-center gap-2">
                    @if ($bets !== [])
                        <flux:button variant="primary" wire:click="import">
                            Import {{ count($bets) }} {{ count($bets) === 1 ? 'bet' : 'bets' }}
                        </flux:button>
                    @else
                        <flux:text>There is nothing to import in this file.</flux:text>
                    @endif

                    <flux:button variant="ghost" wire:click="cancel">{{ $bets === [] ? 'Choose another file' : 'Cancel' }}</flux:button>
                </div>
            </div>
        @endif
    </x-settings.layout>
</section>
