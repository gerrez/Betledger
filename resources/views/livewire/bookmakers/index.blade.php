<div>
    <div class="mb-5 flex items-start justify-between gap-4">
        <x-page-heading title="Bookmakers" description="The bookmakers you bet with, their currency and exchange rate." class="mb-0" />

        <flux:button variant="primary" icon="plus" wire:click="create" class="shrink-0">Add</flux:button>
    </div>

    @if ($bookmakers->isEmpty())
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bookmakers yet</p>
            <p class="mt-1 text-sm text-muted">Add the bookmakers you bet with. Every bet you record is placed at one of them.</p>
        </section>
    @else
        <ul class="divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
            @foreach ($bookmakers as $bookmaker)
                <li wire:key="bookmaker-{{ $bookmaker->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-[15px] font-medium">
                            <span class="truncate @if (! $bookmaker->is_active) text-muted @endif">{{ $bookmaker->name }}</span>

                            @unless ($bookmaker->is_active)
                                <flux:badge size="sm">Inactive</flux:badge>
                            @endunless
                        </div>

                        <p class="mt-0.5 text-sm text-muted tabular-nums">
                            @if ($bookmaker->currency === $baseCurrency)
                                {{ $bookmaker->currency->value }} · base currency
                            @else
                                1 {{ $bookmaker->currency->value }} = {{ $bookmaker->formattedExchangeRate() }} {{ $baseCurrency->value }}
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm" wire:click="edit({{ $bookmaker->id }})">Edit</flux:button>

                        @if ($bookmaker->is_active)
                            <flux:button size="sm" variant="ghost" wire:click="deactivate({{ $bookmaker->id }})">Deactivate</flux:button>
                        @else
                            <flux:button size="sm" variant="ghost" wire:click="reactivate({{ $bookmaker->id }})">Reactivate</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-3 text-sm text-muted">Inactive bookmakers are hidden when you record a bet, but their bets stay in your history and statistics.</p>
    @endif

    <flux:modal wire:model.self="showForm" class="w-full md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? 'Add bookmaker' : 'Edit bookmaker' }}</flux:heading>

            <flux:input wire:model="name" label="Name" required autocomplete="off" />

            <flux:select wire:model.live="currency" label="Currency">
                @foreach ($currencies as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($currency === $baseCurrency->value)
                <flux:input
                    label="Exchange rate"
                    value="1"
                    disabled
                    description="This is your base currency, so the rate is always 1."
                />
            @else
                <flux:input
                    wire:model="exchange_rate"
                    label="Exchange rate"
                    inputmode="decimal"
                    autocomplete="off"
                    required
                    :description="'How many '.$baseCurrency->value.' you get for 1 '.$currency.'. Bets copy the rate when you save them, so changing it later does not rewrite old bets.'"
                />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
