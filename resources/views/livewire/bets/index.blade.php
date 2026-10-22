<div class="max-w-2xl">
    <x-page-heading title="Bets" />

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-4" :heading="session('status')" />
    @endif

    @if (! $hasBets)
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bets yet</p>
            <p class="mt-1 text-sm text-muted">Record the bets you place and they show up here, ready to settle when the result is in.</p>

            <flux:button :href="route('bets.create')" wire:navigate variant="primary" icon="plus" class="mt-4">New bet</flux:button>
        </section>
    @else
        <div class="flex flex-col gap-3.5">
            <div role="tablist" aria-label="Bet status" class="grid grid-cols-3 gap-1 rounded-[14px] bg-line p-1">
                @foreach ($tabs as $value => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:key="tab-{{ $value }}"
                        wire:click="$set('status', '{{ $value }}')"
                        aria-selected="{{ $value === $tab->value ? 'true' : 'false' }}"
                        @class([
                            'h-11 rounded-[11px] text-[15px]',
                            'bg-surface font-semibold text-ink shadow-sm' => $value === $tab->value,
                            'font-medium text-muted hover:text-ink' => $value !== $tab->value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="-mx-5 flex gap-2 overflow-x-auto px-5 pb-0.5 lg:mx-0 lg:flex-wrap lg:px-0">
                <x-select variant="pill" wire:model.live="bookmaker" label="Bookmaker">
                    <x-select.option value="">All bookmakers</x-select.option>
                    @foreach ($bookmakers as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="bookmaker-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select variant="pill" wire:model.live="sport" label="Sport">
                    <x-select.option value="">All sports</x-select.option>
                    @foreach ($sports as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="sport-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select variant="pill" wire:model.live="sort" label="Order">
                    @foreach ($sorts as $option)
                        {{-- The tab's default order is the empty value, so a new tab shows it selected. --}}
                        <x-select.option value="{{ $loop->first ? '' : $option->value }}" wire:key="sort-{{ $tab->value }}-{{ $option->value }}">{{ $option->label() }}</x-select.option>
                    @endforeach
                </x-select>
            </div>

            @if ($cards->isEmpty())
                <section class="rounded-[20px] border border-line bg-surface p-5">
                    <p class="text-[15px] font-medium">{{ $emptyMessage }}</p>

                    @if ($isFiltered)
                        <flux:button size="sm" wire:click="clearFilters" class="mt-3">Clear filters</flux:button>
                    @endif
                </section>
            @else
                @foreach ($cards as $card)
                    <article wire:key="bet-{{ $card->id }}" class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
                        <a href="{{ route('bets.show', $card->id) }}" wire:navigate class="flex items-start gap-3 rounded-lg text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                            <div class="flex min-w-0 grow flex-col gap-0.5">
                                <span class="text-base font-semibold">{{ $card->title }}</span>
                                <span class="text-[13px] text-muted">{{ $card->meta }}</span>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-0.5">
                                <span class="text-base font-semibold">{{ $card->stake }}</span>
                                <span class="text-[13px] text-muted">@ {{ $card->totalOdds }}</span>
                            </div>
                        </a>

                        @if ($card->isFreeBet)
                            <span class="self-start rounded-full bg-accent-soft px-2.5 py-1 text-xs font-semibold text-accent-soft-content">FREE BET</span>
                        @endif

                        @if ($card->isAccumulator)
                            <ol class="flex flex-col gap-1.5">
                                @foreach ($card->legs as $leg)
                                    <li wire:key="bet-{{ $card->id }}-leg-{{ $loop->index }}" class="flex items-center gap-2.5 rounded-xl bg-canvas px-3 py-2.5">
                                        <span
                                            aria-hidden="true"
                                            @class([
                                                'size-2.5 shrink-0 rounded-full',
                                                'bg-profit-fill' => $leg['tone'] === 'won',
                                                'bg-loss-fill' => $leg['tone'] === 'lost',
                                                'bg-zinc-400' => $leg['tone'] === 'void',
                                                'border-2 border-zinc-400' => $leg['tone'] === 'pending',
                                            ])
                                        ></span>

                                        <div class="flex min-w-0 grow flex-col gap-0.5">
                                            <span class="text-sm font-medium">{{ $leg['pick'] }}</span>
                                            <span class="text-xs text-muted">{{ $leg['event'] }}</span>
                                        </div>

                                        <div class="flex shrink-0 flex-col items-end gap-0.5">
                                            <span class="text-sm font-semibold">{{ $leg['odds'] }}</span>
                                            <span class="text-xs text-muted">{{ $leg['status'] }}</span>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <div class="flex items-center justify-between gap-3 rounded-xl bg-canvas px-3 py-2.5">
                                <span class="text-[15px] font-medium">{{ $card->legs[0]['pick'] }}</span>
                                <span class="text-[15px] font-semibold">{{ $card->legs[0]['odds'] }}</span>
                            </div>
                        @endif

                        @if ($card->lastResult !== null)
                            <div class="flex items-center gap-3 rounded-xl bg-canvas px-3 py-2.5">
                                <span class="grow text-sm font-semibold">{{ $card->lastResult }}</span>
                                <button type="button" wire:click="undo({{ $card->id }})" class="h-11 shrink-0 rounded-[10px] border border-ink/12 bg-surface px-3.5 text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Undo</button>
                            </div>
                        @endif

                        @if ($card->settle !== null)
                            <div role="group" aria-labelledby="settle-{{ $card->id }}" class="flex flex-col gap-2">
                                <span id="settle-{{ $card->id }}" class="text-[13px] text-muted">{{ $card->settle['prompt'] }}</span>

                                <div class="grid grid-cols-5 gap-1.5">
                                    @foreach ($results as $result)
                                        <button
                                            type="button"
                                            wire:key="bet-{{ $card->id }}-result-{{ $result->value }}"
                                            wire:click="settle({{ $card->settle['selection'] }}, '{{ $result->value }}')"
                                            class="h-11 rounded-[10px] border border-zinc-300 bg-surface text-[13px] font-semibold text-ink hover:border-accent dark:border-zinc-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                        >{{ $result->label() }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($card->result !== null)
                            <div @class([
                                'flex items-center gap-3 rounded-xl px-3 py-2.5',
                                'bg-profit-soft text-profit' => $card->result['tone'] === 'profit',
                                'bg-loss-soft text-loss' => $card->result['tone'] === 'loss',
                                'bg-canvas text-ink' => $card->result['tone'] === 'even',
                            ])>
                                <div class="flex grow flex-col gap-0.5">
                                    <span class="text-sm font-semibold">{{ $card->result['label'] }}</span>
                                    <span class="text-[13px]">Payout {{ $card->result['payout'] }} · profit {{ $card->result['profit'] }}</span>
                                </div>

                                @if ($card->canUndo)
                                    <button type="button" wire:click="undo({{ $card->id }})" class="h-11 shrink-0 rounded-[10px] border border-ink/12 bg-surface px-3.5 text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Undo</button>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach

                <flux:pagination :paginator="$bets" scroll-to />
            @endif
        </div>
    @endif
</div>
