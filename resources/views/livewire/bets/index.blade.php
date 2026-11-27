<div class="max-w-2xl">
    <x-page-heading title="Bets" />

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-4" :heading="session('status')" />
    @endif

    @if ($notice !== null)
        <flux:callout variant="success" icon="check-circle" class="mb-4" :heading="$notice" />
    @endif

    @if (! $hasBets)
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bets yet</p>
            <p class="mt-1 text-sm text-muted">Record the bets you place and they show up here, ready to settle when the result is in.</p>

            <flux:button :href="route('bets.create')" wire:navigate variant="primary" icon="plus" class="mt-4">New bet</flux:button>
        </section>
    @else
        <div class="flex flex-col gap-3.5">
            <div class="flex gap-2">
                <div role="search" class="min-w-0 grow">
                    <flux:input
                        type="text"
                        enterkeyhint="search"
                        wire:model.live.debounce.300ms="search"
                        icon="magnifying-glass"
                        placeholder="Search events, picks, teams, notes"
                        aria-label="Search bets"
                        maxlength="{{ $this::MAX_SEARCH_LENGTH }}"
                        autocomplete="off"
                        clearable
                        class="max-lg:*:h-11"
                    />
                </div>

                <flux:modal.trigger name="bet-filters">
                    <button
                        type="button"
                        class="flex h-11 shrink-0 items-center gap-2 rounded-full border border-line bg-surface px-4 text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent lg:h-10"
                    >
                        <flux:icon.adjustments-horizontal variant="mini" />
                        Filters{{ $chips === [] ? '' : ' · '.count($chips) }}
                    </button>
                </flux:modal.trigger>
            </div>

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

            <div class="-mx-5 flex items-center gap-2 overflow-x-auto px-5 pb-0.5 lg:mx-0 lg:flex-wrap lg:px-0">
                <x-select variant="pill" wire:model.live="sort" label="Order">
                    @foreach ($sorts as $option)
                        {{-- The tab's default order is the empty value, so a new tab shows it selected. --}}
                        <x-select.option value="{{ $loop->first ? '' : $option->value }}" wire:key="sort-{{ $tab->value }}-{{ $option->value }}">{{ $option->label() }}</x-select.option>
                    @endforeach
                </x-select>

                <button
                    type="button"
                    wire:click="{{ $selecting ? 'stopSelecting' : 'startSelecting' }}"
                    aria-pressed="{{ $selecting ? 'true' : 'false' }}"
                    @class([
                        'flex h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent lg:h-10',
                        'border-line bg-surface text-ink' => ! $selecting,
                        'border-accent bg-accent-soft text-accent-soft-content' => $selecting,
                    ])
                >
                    <flux:icon.check-circle variant="mini" />
                    {{ $selecting ? 'Done' : 'Select' }}
                </button>

                @if ($chips !== [])
                    <ul aria-label="Active filters" class="flex shrink-0 gap-2 lg:flex-wrap">
                        @foreach ($chips as $chip)
                            <li wire:key="chip-{{ $chip['filter'] }}" class="flex h-11 shrink-0 items-center gap-1 rounded-full bg-accent-soft pr-1.5 pl-4 text-sm font-medium whitespace-nowrap text-accent-soft-content lg:h-10">
                                {{ $chip['label'] }}
                                <button
                                    type="button"
                                    wire:click="removeFilter('{{ $chip['filter'] }}')"
                                    aria-label="Remove filter {{ $chip['label'] }}"
                                    class="relative flex size-7 items-center justify-center rounded-full after:absolute after:-inset-2 hover:bg-accent/15 focus-visible:outline-2 focus-visible:outline-accent"
                                >
                                    <flux:icon.x-mark variant="micro" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($isNarrowed)
                    <button
                        type="button"
                        wire:click="clearAll"
                        class="h-11 shrink-0 rounded-full px-3 text-sm font-medium whitespace-nowrap text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent lg:h-10"
                    >{{ $clearLabel }}</button>
                @endif
            </div>

            @if ($isNarrowed && $cards->isNotEmpty())
                <p class="text-[13px] text-muted" aria-live="polite">{{ $matchCount }}</p>
            @endif

            @if ($selecting && $cards->isNotEmpty())
                <section aria-label="Selected bets" class="sticky top-2 z-10 flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-3 shadow-sm">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="grow text-sm font-semibold" aria-live="polite">{{ $hasSelection ? "{$selectedCount} selected" : 'Tick the bets to change' }}</span>

                        @if ($selectsAllMatching)
                            <button type="button" wire:click="clearSelection" class="h-11 text-sm font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent lg:h-8">Clear selection</button>
                        @else
                            <button type="button" wire:click="selectAllMatching" class="h-11 text-sm font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent lg:h-8">Select all {{ $matchingTotal }}</button>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <flux:modal.trigger name="bulk-tag">
                            <flux:button icon="tag" :disabled="! $hasSelection" class="w-full max-lg:h-11">Add or remove tag</flux:button>
                        </flux:modal.trigger>

                        <flux:button
                            variant="danger"
                            icon="trash"
                            wire:click="deleteSelected"
                            :disabled="! $hasSelection"
                            data-confirm="Their selections, results and tags are deleted too. This can’t be undone."
                            data-confirm-title="Delete {{ $selectedCount }}?"
                            data-confirm-button="Delete {{ $selectedCount }}"
                            data-confirm-danger
                            class="w-full max-lg:h-11"
                        >Delete</flux:button>
                    </div>
                </section>
            @endif

            @if ($cards->isEmpty())
                <section class="rounded-[20px] border border-line bg-surface p-5">
                    <p class="text-[15px] font-medium">{{ $emptyMessage }}</p>

                    @if ($isNarrowed)
                        <flux:button size="sm" wire:click="clearAll" class="mt-3">{{ $clearLabel }}</flux:button>
                    @endif
                </section>
            @else
                @foreach ($cards as $card)
                    <article wire:key="bet-{{ $card->id }}" class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
                        <div class="flex items-start gap-3">
                            @if ($selecting)
                                <label class="relative mt-0.5 flex size-6 shrink-0 cursor-pointer items-center justify-center after:absolute after:-inset-2.5">
                                    @if ($selectsAllMatching)
                                        <input type="checkbox" checked disabled class="size-5 accent-accent" />
                                    @else
                                        <input type="checkbox" wire:model.live="selected" value="{{ $card->id }}" class="size-5 accent-accent" />
                                    @endif
                                    <span class="sr-only">Select {{ $card->title }}</span>
                                </label>
                            @endif

                            <a href="{{ route('bets.show', $card->id) }}" wire:navigate class="flex min-w-0 grow items-start gap-3 rounded-lg text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                                <div class="flex min-w-0 grow flex-col gap-0.5">
                                    <span class="text-base font-semibold">{{ $card->title }}</span>
                                    <span class="text-[13px] text-muted">{{ $card->meta }}</span>
                                </div>

                                <div class="flex shrink-0 flex-col items-end gap-0.5">
                                    <span class="text-base font-semibold">{{ $card->stake }}</span>
                                    <span class="text-[13px] text-muted">@ {{ $card->totalOdds }}</span>
                                </div>
                            </a>
                        </div>

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

                        @if ($card->lastResult !== null && ! $selecting)
                            <div class="flex items-center gap-3 rounded-xl bg-canvas px-3 py-2.5">
                                <span class="grow text-sm font-semibold">{{ $card->lastResult }}</span>
                                <button type="button" wire:click="undo({{ $card->id }})" class="h-11 shrink-0 rounded-[10px] border border-ink/12 bg-surface px-3.5 text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Undo</button>
                            </div>
                        @endif

                        @if ($card->settle !== null && ! $selecting)
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

                                @if ($card->canUndo && ! $selecting)
                                    <button type="button" wire:click="undo({{ $card->id }})" class="h-11 shrink-0 rounded-[10px] border border-ink/12 bg-surface px-3.5 text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Undo</button>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach

                <flux:pagination :paginator="$bets" scroll-to />
            @endif
        </div>

        <flux:modal name="bet-filters" flyout class="w-full max-w-none md:w-[26rem]">
            <div class="flex flex-col gap-5">
                <flux:heading size="lg">Filters</flux:heading>

                <x-select wire:model.live="bookmaker" label="Bookmaker">
                    <x-select.option value="">All bookmakers</x-select.option>
                    @foreach ($bookmakers as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="bookmaker-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select wire:model.live="sport" label="Sport">
                    <x-select.option value="">All sports</x-select.option>
                    @foreach ($sports as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="sport-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select wire:model.live="competition" label="Competition">
                    <x-select.option value="">All competitions</x-select.option>
                    @foreach ($competitions as $sportName => $options)
                        <x-select.group heading="{{ $sportName }}" wire:key="competition-group-{{ $loop->index }}">
                            @foreach ($options as $option)
                                <x-select.option value="{{ $option->id }}" wire:key="competition-{{ $option->id }}">{{ $option->name }}</x-select.option>
                            @endforeach
                        </x-select.group>
                    @endforeach
                </x-select>

                <x-select wire:model.live="market" label="Market">
                    <x-select.option value="">All markets</x-select.option>
                    @foreach ($markets as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="market-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select wire:model.live="tipster" label="Tipster">
                    <x-select.option value="">All tipsters</x-select.option>
                    @foreach ($tipsters as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="tipster-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select wire:model.live="tag" label="Tag">
                    <x-select.option value="">All tags</x-select.option>
                    @foreach ($tags as $option)
                        <x-select.option value="{{ $option->id }}" wire:key="tag-{{ $option->id }}">{{ $option->name }}</x-select.option>
                    @endforeach
                </x-select>

                <x-select wire:model.live="type" label="Bet type">
                    <x-select.option value="">All bet types</x-select.option>
                    @foreach ($types as $value => $label)
                        <x-select.option value="{{ $value }}" wire:key="type-{{ $value }}">{{ $label }}</x-select.option>
                    @endforeach
                </x-select>

                <div class="flex flex-col gap-2">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="date" wire:model.live="from" label="From" :max="$to !== '' ? $to : null" />
                        <flux:input type="date" wire:model.live="to" label="To" :min="$from !== '' ? $from : null" />
                    </div>
                    <p class="text-xs text-muted">Settled bets count on the date of their event, open bets on the day they were placed.</p>
                </div>

                @if ($importBatches !== [] || $importBatch !== '')
                    <x-select wire:model.live="importBatch" label="Import">
                        <x-select.option value="">All bets</x-select.option>
                        @foreach ($importBatches as $option)
                            <x-select.option value="{{ $option['id'] }}" wire:key="import-{{ $option['id'] }}">{{ $option['label'] }}</x-select.option>
                        @endforeach
                    </x-select>
                @endif

                <div class="flex gap-2">
                    @if ($chips !== [])
                        <flux:button wire:click="clearFilters">Clear filters</flux:button>
                    @endif

                    <flux:spacer />

                    <flux:modal.close>
                        <flux:button variant="primary">Show {{ $matchCount }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="bulk-tag" class="w-full max-w-lg">
            <div class="flex flex-col gap-5">
                <flux:heading size="lg">Tag {{ $selectedCount }}</flux:heading>

                @if ($tags->isEmpty())
                    <flux:text>You have no tags yet. Add them under Lists, or on a bet.</flux:text>

                    <div class="flex justify-end">
                        <flux:modal.close>
                            <flux:button variant="filled">Close</flux:button>
                        </flux:modal.close>
                    </div>
                @else
                    <x-select wire:model="bulkTag" label="Tag to add or remove">
                        <x-select.option value="">Choose a tag</x-select.option>
                        @foreach ($tags as $option)
                            <x-select.option value="{{ $option->id }}" wire:key="bulk-tag-{{ $option->id }}">{{ $option->name }}</x-select.option>
                        @endforeach
                    </x-select>

                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">Cancel</flux:button>
                        </flux:modal.close>

                        <flux:button wire:click="removeTagFromSelected">Remove from {{ $selectedCount }}</flux:button>
                        <flux:button variant="primary" wire:click="addTagToSelected">Add to {{ $selectedCount }}</flux:button>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif
</div>
