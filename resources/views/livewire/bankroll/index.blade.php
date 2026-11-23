<div class="flex flex-col gap-5">
    <x-page-heading title="Bankroll" description="Deposits, withdrawals and the balance at each bookmaker." class="mb-0" />

    @if ($status !== null)
        <flux:callout variant="success" icon="check-circle" :heading="$status" />
    @endif

    @if ($rows === [])
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bookmakers yet</p>
            <p class="mt-1 text-sm text-muted">Add the bookmakers you bet with, then record your deposits and withdrawals here.</p>

            <flux:button :href="route('bookmakers.index')" wire:navigate variant="primary" class="mt-4 max-lg:h-11">Go to bookmakers</flux:button>
        </section>
    @else
        <section aria-labelledby="bankroll-total" class="flex flex-col gap-4 rounded-[20px] bg-surface p-5 shadow-[0_1px_2px_rgba(22,21,31,0.04),0_6px_20px_rgba(22,21,31,0.05)] dark:border dark:border-line">
            <div class="flex flex-col gap-1">
                <h2 id="bankroll-total" class="text-sm text-muted">Total balance · {{ $baseCurrency->value }}</h2>
                <span @class([
                    'text-[38px] leading-tight font-semibold tracking-tight tabular-nums',
                    'text-loss' => $total->balanceTone === 'loss',
                ])>{{ $total->balance }}</span>
            </div>

            @include('livewire.bankroll.tiles', ['figures' => $total])

            @unless ($bankroll->hasActivity())
                <p class="text-sm text-muted">Add your first deposit at a bookmaker below to start tracking its balance.</p>
            @endunless
        </section>

        <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
            @foreach ($rows as $entry)

                <section wire:key="bankroll-{{ $entry['row']->bookmakerId }}" aria-labelledby="bookmaker-{{ $entry['row']->bookmakerId }}" class="flex flex-col gap-4 rounded-[20px] border border-line bg-surface p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <h2 id="bookmaker-{{ $entry['row']->bookmakerId }}" @class(['truncate text-[17px] font-semibold', 'text-muted' => ! $entry['row']->isActive])>
                                {{ $entry['row']->name }}@unless ($entry['row']->isActive) <span class="font-medium">(inactive)</span>@endunless
                            </h2>
                            <span class="text-[13px] text-muted">{{ $entry['row']->figures->currency->value }}</span>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-0.5">
                            <span class="text-[13px] text-muted">Balance</span>
                            <span @class([
                                'text-[22px] font-semibold tracking-tight tabular-nums',
                                'text-loss' => $entry['figures']->balanceTone === 'loss',
                            ])>{{ $entry['figures']->balance }}</span>
                        </div>
                    </div>

                    @if ($entry['row']->inBaseCurrency)
                        <p class="text-[13px] text-muted">Bets in more than one currency, shown in {{ $baseCurrency->value }}.</p>
                    @endif

                    @include('livewire.bankroll.tiles', ['figures' => $entry['figures']])

                    <p class="text-[13px] text-muted tabular-nums">Deposited {{ $entry['deposited'] }} · Withdrawn {{ $entry['withdrawn'] }}</p>

                    <div class="flex flex-wrap gap-2">
                        @if ($entry['row']->isActive)
                            <flux:button size="sm" icon="plus" class="max-lg:h-11" wire:click="addTransaction({{ $entry['row']->bookmakerId }}, 'deposit')">Add deposit</flux:button>
                            <flux:button size="sm" icon="minus" class="max-lg:h-11" wire:click="addTransaction({{ $entry['row']->bookmakerId }}, 'withdrawal')">Add withdrawal</flux:button>
                        @endif

                        <flux:button
                            size="sm"
                            variant="ghost"
                            class="max-lg:h-11"
                            :icon-trailing="$entry['isExpanded'] ? 'chevron-up' : 'chevron-down'"
                            aria-expanded="{{ $entry['isExpanded'] ? 'true' : 'false' }}"
                            aria-controls="transactions-{{ $entry['row']->bookmakerId }}"
                            wire:click="toggleTransactions({{ $entry['row']->bookmakerId }})"
                        >Transactions</flux:button>
                    </div>

                    @if ($entry['isExpanded'] && $transactions !== null)
                        <div id="transactions-{{ $entry['row']->bookmakerId }}">
                            @if ($transactions === [])
                                <p class="text-sm text-muted">No deposits or withdrawals yet.</p>
                            @else
                                <ul class="divide-y divide-line border-t border-line">
                                    @foreach ($transactions as $transaction)
                                        <li wire:key="transaction-{{ $transaction['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3">
                                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                                <span class="text-[15px] font-medium">{{ $transaction['type'] }}</span>
                                                <span class="truncate text-[13px] text-muted">
                                                    {{ $transaction['meta'] }}
                                                </span>
                                            </div>

                                            <span class="text-[15px] font-semibold tabular-nums">
                                                {{ $transaction['amount'] }}
                                            </span>

                                            <div class="flex gap-1">
                                                <flux:button size="sm" variant="ghost" class="max-lg:h-11" wire:click="editTransaction({{ $transaction['id'] }})">Edit</flux:button>
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    class="max-lg:h-11"
                                                    wire:click="deleteTransaction({{ $transaction['id'] }})"
                                                    data-confirm="The balance is recalculated without it. This can’t be undone."
                                                    data-confirm-title="Delete this {{ mb_strtolower($transaction['type']) }}?"
                                                    data-confirm-button="Delete"
                                                    data-confirm-danger
                                                >Delete</flux:button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="showForm" class="w-full md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? 'Add transaction' : 'Edit transaction' }}</flux:heading>

            @if ($editingId === null)
                <x-select wire:model="bookmaker_id" label="Bookmaker">
                    @foreach ($activeBookmakers as $option)
                        <x-select.option :value="(string) $option->id" wire:key="bookmaker-option-{{ $option->id }}">{{ $option->name }} ({{ $option->currency->value }})</x-select.option>
                    @endforeach
                </x-select>
                <flux:error name="bookmaker_id" />
            @else
                <flux:input label="Bookmaker" :value="$editingBookmaker === null ? '' : $editingBookmaker->name.' ('.$editingBookmaker->currency->value.')'" disabled />
            @endif

            <flux:radio.group wire:model="type" label="Type">
                @foreach ($types as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" />
                @endforeach
            </flux:radio.group>

            <flux:input wire:model="amount" label="Amount" inputmode="decimal" autocomplete="off" required />

            <flux:input wire:model="occurred_on" label="Date" type="date" :max="$today" required />

            <flux:input wire:model="note" label="Note" description="Optional, e.g. a bonus or the payment method." maxlength="255" autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
