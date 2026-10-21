<div class="mx-auto max-w-xl">
    <header class="mb-4 flex items-center gap-2">
        <a
            href="{{ $bet === null ? route('dashboard') : route('bets.index') }}"
            wire:navigate
            aria-label="Close"
            class="flex size-11 shrink-0 items-center justify-center rounded-[14px] border border-line bg-surface text-ink hover:bg-canvas"
        >
            <flux:icon.x-mark variant="mini" />
        </a>

        <h1 class="flex-1 pr-[52px] text-center text-xl font-semibold">{{ $bet === null ? 'New bet' : 'Edit bet' }}</h1>
    </header>

    @if ($bookmakers->isEmpty())
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">Add a bookmaker first</p>
            <p class="mt-1 text-sm text-muted">Every bet is placed at one of your bookmakers, in its currency.</p>

            <flux:button :href="route('bookmakers.index')" wire:navigate variant="primary" class="mt-4">Go to bookmakers</flux:button>
        </section>
    @else
        <form wire:submit="save" class="flex flex-col gap-4">
            @if ($settlement !== null)
                <section class="flex flex-col gap-1 rounded-[18px] border border-line bg-surface p-4 text-sm">
                    <p class="text-[15px] font-medium">Settled · payout {{ $settlement['payout'] }} · profit {{ $settlement['profit'] }}</p>
                    <p class="text-muted">Selections keep their results. Changing the stake, odds or selections settles the bet again.</p>
                    @if ($settlement['isManual'])
                        <p class="mt-1 font-medium text-loss">You entered this payout yourself. Changing the stake, free bet, odds or selections replaces it with the calculated payout.</p>
                    @endif
                </section>
            @endif

            <div role="group" aria-label="Bet type" class="grid grid-cols-2 gap-1 rounded-[14px] bg-line p-1">
                <button
                    type="button"
                    wire:click="useSingle"
                    @if ($isAccumulator) wire:confirm="Keep only the first selection?" @endif
                    aria-pressed="{{ $isAccumulator ? 'false' : 'true' }}"
                    @class([
                        'h-10 rounded-[11px] text-[15px]',
                        'bg-surface font-semibold text-ink shadow-sm' => ! $isAccumulator,
                        'font-medium text-muted' => $isAccumulator,
                    ])
                >Single</button>
                <button
                    type="button"
                    wire:click="useAccumulator"
                    aria-pressed="{{ $isAccumulator ? 'true' : 'false' }}"
                    @class([
                        'h-10 rounded-[11px] text-[15px]',
                        'bg-surface font-semibold text-ink shadow-sm' => $isAccumulator,
                        'font-medium text-muted' => ! $isAccumulator,
                    ])
                >Accumulator</button>
            </div>

            <section class="grid grid-cols-2 gap-3 rounded-[18px] border border-line bg-surface p-4">
                <flux:select wire:model.live="bookmaker_id" label="Bookmaker">
                    @foreach ($bookmakers as $option)
                        <flux:select.option :value="$option->id" wire:key="bookmaker-{{ $option->id }}">{{ $option->name }} · {{ $option->currency->value }}{{ $option->is_active ? '' : ' (inactive)' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="placed_at" type="datetime-local" label="Placed" required />
            </section>

            @foreach ($selections as $index => $selection)
                <section wire:key="selection-{{ $index }}-of-{{ count($selections) }}" class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
                    <div class="flex min-h-11 items-center gap-2">
                        <h2 class="flex-1 text-base font-semibold">{{ $isAccumulator ? 'Selection '.($index + 1) : 'Selection' }}</h2>

                        @if ($results[$index] !== null)
                            <span class="rounded-full border border-line px-2.5 py-1 text-sm font-medium">{{ $results[$index] }}</span>
                        @endif

                        @if (count($selections) > 2)
                            <button
                                type="button"
                                wire:click="removeSelection({{ $index }})"
                                aria-label="Remove selection {{ $index + 1 }}"
                                class="flex size-11 items-center justify-center rounded-xl text-muted hover:bg-canvas hover:text-ink"
                            >
                                <flux:icon.trash variant="mini" />
                            </button>
                        @endif
                    </div>

                    <flux:input
                        wire:model.blur="selections.{{ $index }}.sport"
                        label="Sport"
                        list="sports-list"
                        autocomplete="off"
                        placeholder="e.g. Football"
                        required
                    />

                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-medium" id="teams-label-{{ $index }}">Teams / participants</span>

                        @if ($selection['teams'] !== [])
                            <ul class="flex flex-wrap gap-2" aria-labelledby="teams-label-{{ $index }}">
                                @foreach ($selection['teams'] as $teamIndex => $team)
                                    <li wire:key="team-{{ $index }}-{{ $teamIndex }}" class="flex h-9 items-center gap-1 rounded-full bg-accent-soft pr-1 pl-3 text-sm font-medium text-accent-soft-content">
                                        {{ $team }}
                                        <button
                                            type="button"
                                            wire:click="removeTeam({{ $index }}, {{ $teamIndex }})"
                                            aria-label="Remove {{ $team }}"
                                            class="flex size-7 items-center justify-center rounded-full hover:bg-accent/15"
                                        >
                                            <flux:icon.x-mark variant="micro" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="flex gap-2">
                            <flux:input
                                wire:model="selections.{{ $index }}.team_name"
                                wire:keydown.enter.prevent="addTeam({{ $index }})"
                                aria-labelledby="teams-label-{{ $index }}"
                                list="teams-list-{{ $index }}"
                                autocomplete="off"
                                placeholder="Type a name, then Add"
                                class="flex-1"
                            />
                            <flux:button wire:click="addTeam({{ $index }})" icon="plus">Add</flux:button>
                        </div>

                        <datalist id="teams-list-{{ $index }}">
                            @foreach ($sportSuggestions[$index]['teams'] as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <flux:input
                        wire:model="selections.{{ $index }}.event_name"
                        label="Event"
                        autocomplete="off"
                        placeholder="Filled in from the teams"
                    />

                    <flux:input wire:model="selections.{{ $index }}.pick" label="Pick" autocomplete="off" placeholder="e.g. Arsenal −0.25" required />

                    <div class="grid grid-cols-2 gap-3">
                        <flux:input
                            wire:model.live.debounce.400ms="selections.{{ $index }}.odds"
                            :loading="false"
                            label="Odds"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="1.95"
                            required
                        />

                        <flux:input wire:model="selections.{{ $index }}.event_starts_at" type="datetime-local" label="Starts" />
                    </div>

                    @if ($showDetails)
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input
                                wire:model="selections.{{ $index }}.competition"
                                label="Competition"
                                list="competitions-list-{{ $index }}"
                                autocomplete="off"
                                class="col-span-2"
                            />

                            <flux:input wire:model="selections.{{ $index }}.market" label="Market" list="markets-list" autocomplete="off" />

                            <flux:input
                                wire:model="selections.{{ $index }}.closing_odds"
                                label="Closing odds"
                                inputmode="decimal"
                                autocomplete="off"
                                placeholder="Add later"
                            />
                        </div>

                        <datalist id="competitions-list-{{ $index }}">
                            @foreach ($sportSuggestions[$index]['competitions'] as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>
                    @endif
                </section>
            @endforeach

            @if ($isAccumulator)
                <div class="flex flex-col gap-3">
                    @if ($canAddSelection)
                        <button
                            type="button"
                            wire:click="addSelection"
                            class="flex h-12 items-center justify-center gap-1.5 rounded-[14px] border border-dashed border-zinc-300 text-[15px] font-semibold text-accent-content hover:bg-accent-soft dark:border-zinc-600"
                        >
                            <flux:icon.plus variant="mini" /> Add selection
                        </button>
                    @endif

                    <div class="flex items-baseline justify-between gap-3 px-1">
                        <span class="text-sm text-muted">Total odds ({{ count($selections) }} selections)</span>
                        <span class="text-[17px] font-semibold">{{ $totalOdds ?? '—' }}</span>
                    </div>

                    <flux:error name="selections" />
                </div>
            @endif

            <section class="flex flex-col gap-4 rounded-[18px] border border-line bg-surface p-4">
                <div class="flex flex-col gap-2">
                    <flux:input.group label="Stake">
                        <flux:input
                            wire:model.live.debounce.400ms="stake"
                            :loading="false"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="0.00"
                            aria-label="Stake"
                            class="text-lg font-semibold"
                        />
                        <flux:input.group.suffix>{{ $bookmaker?->currency->value }}</flux:input.group.suffix>
                    </flux:input.group>

                    <flux:error name="stake" />

                    @if ($stakeInBaseCurrency !== null)
                        <p class="text-sm text-muted">≈ {{ $stakeInBaseCurrency }} at {{ $bookmaker->name }}’s rate {{ $exchange_rate }}</p>
                    @endif
                </div>

                <flux:switch
                    wire:model.live="is_free_bet"
                    label="Free bet"
                    description="Stake isn’t returned or counted as turnover"
                />
            </section>

            <section class="flex flex-col gap-2 rounded-[18px] bg-accent-soft p-4 text-accent-soft-content" aria-live="polite">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-sm font-medium">Payout if it wins</span>
                    <span class="text-xl font-semibold">{{ $preview['payout'] ?? '—' }}</span>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-sm font-medium">Profit</span>
                    <span class="text-base font-semibold">{{ $preview['profit'] ?? '—' }}</span>
                </div>
            </section>

            <button
                type="button"
                wire:click="$toggle('showDetails')"
                aria-expanded="{{ $showDetails ? 'true' : 'false' }}"
                class="flex min-h-11 items-center justify-center gap-1.5 rounded-xl text-[15px] font-medium text-accent-content hover:bg-accent-soft"
            >
                {{ $showDetails ? 'Fewer details' : 'More details: competition, market, tipster, tags…' }}
                @if ($showDetails)
                    <flux:icon.chevron-up variant="mini" />
                @else
                    <flux:icon.chevron-down variant="mini" />
                @endif
            </button>

            @if ($showDetails)
                <section class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input wire:model="tipster" label="Tipster / strategy" list="tipsters-list" autocomplete="off" />

                        @if ($isBaseCurrency)
                            <flux:input label="Exchange rate" value="1" disabled />
                        @else
                            <flux:input
                                wire:model.live.blur="exchange_rate"
                                :loading="false"
                                label="Exchange rate"
                                inputmode="decimal"
                                autocomplete="off"
                                required
                            />
                        @endif
                    </div>

                    @unless ($isBaseCurrency)
                        <p class="text-sm text-muted">1 {{ $bookmaker?->currency->value }} = this many {{ $baseCurrency->value }}. Copied from the bookmaker; change it if this bet got a different rate.</p>
                    @endunless

                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-medium" id="tags-label">Tags</span>

                        @if ($tags !== [])
                            <ul class="flex flex-wrap gap-2" aria-labelledby="tags-label">
                                @foreach ($tags as $tagIndex => $tag)
                                    <li wire:key="tag-{{ $tagIndex }}" class="flex h-9 items-center gap-1 rounded-full bg-accent-soft pr-1 pl-3 text-sm font-medium text-accent-soft-content">
                                        {{ $tag }}
                                        <button
                                            type="button"
                                            wire:click="removeTag({{ $tagIndex }})"
                                            aria-label="Remove {{ $tag }}"
                                            class="flex size-7 items-center justify-center rounded-full hover:bg-accent/15"
                                        >
                                            <flux:icon.x-mark variant="micro" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="flex gap-2">
                            <flux:input
                                wire:model="tag_name"
                                wire:keydown.enter.prevent="addTag"
                                aria-labelledby="tags-label"
                                list="tags-list"
                                autocomplete="off"
                                placeholder="Type a tag, then Add"
                                class="flex-1"
                            />
                            <flux:button wire:click="addTag" icon="plus">Add</flux:button>
                        </div>
                    </div>

                    <flux:textarea wire:model="notes" label="Notes" rows="3" placeholder="Why you took it, team news…" />
                </section>
            @endif

            <div class="sticky bottom-[calc(max(20px,env(safe-area-inset-bottom))+4.75rem)] -mx-1 bg-canvas/90 px-1 py-2 backdrop-blur lg:bottom-0">
                <flux:button type="submit" variant="primary" class="h-12 w-full text-base">{{ $bet === null ? 'Save bet' : 'Save changes' }}</flux:button>
            </div>
        </form>

        <datalist id="sports-list">
            @foreach ($suggestions['sports'] as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        <datalist id="markets-list">
            @foreach ($suggestions['markets'] as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        <datalist id="tipsters-list">
            @foreach ($suggestions['tipsters'] as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        <datalist id="tags-list">
            @foreach ($suggestions['tags'] as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
    @endif
</div>
