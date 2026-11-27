<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-col gap-1">
            <h1 class="text-[26px] font-semibold tracking-tight lg:text-[30px]">Statistics</h1>
            <p class="text-sm text-muted">All amounts in your base currency, {{ $currency->value }}</p>
        </div>

        @if ($hasBets)
            <div class="flex flex-col gap-1.5">
                <div role="group" aria-label="Date range" class="flex flex-wrap gap-1 rounded-[14px] bg-line p-1">
                    @foreach ($ranges as $option)
                        <button
                            type="button"
                            wire:key="range-{{ $option->value }}"
                            wire:click="useRange('{{ $option->value }}')"
                            aria-pressed="{{ $option === $selectedRange ? 'true' : 'false' }}"
                            @class([
                                'h-11 rounded-[11px] px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                'bg-surface font-semibold text-ink shadow-sm' => $option === $selectedRange,
                                'font-medium text-muted hover:text-ink' => $option !== $selectedRange,
                            ])
                        >{{ $option->label() }}</button>
                    @endforeach
                </div>
                <p class="text-xs text-muted">Settled bets count on the date of their event.</p>
            </div>
        @endif
    </div>

    @if (! $hasBets)
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No statistics yet</p>
            <p class="mt-1 text-sm text-muted">Record your bets and settle them, and your profit, ROI, strike rate and closing line value show up here.</p>

            <flux:button :href="route('bets.create')" wire:navigate variant="primary" icon="plus" class="mt-4">New bet</flux:button>
        </section>
    @else
        @if ($isCustomRange)
            <div class="flex flex-wrap gap-3">
                <flux:input type="date" wire:model.live="from" label="From" :max="$to !== '' ? $to : null" class="w-44" />
                <flux:input type="date" wire:model.live="to" label="To" :min="$from !== '' ? $from : null" class="w-44" />
            </div>
        @endif

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

            <x-select variant="pill" wire:model.live="competition" label="Competition">
                <x-select.option value="">All competitions</x-select.option>
                @foreach ($competitions as $sportName => $options)
                    <x-select.group heading="{{ $sportName }}" wire:key="competition-group-{{ $loop->index }}">
                        @foreach ($options as $option)
                            <x-select.option value="{{ $option->id }}" wire:key="competition-{{ $option->id }}">{{ $option->name }}</x-select.option>
                        @endforeach
                    </x-select.group>
                @endforeach
            </x-select>

            <x-select variant="pill" wire:model.live="team" label="Team">
                <x-select.option value="">All teams</x-select.option>
                @foreach ($teams as $sportName => $options)
                    <x-select.group heading="{{ $sportName }}" wire:key="team-group-{{ $loop->index }}">
                        @foreach ($options as $option)
                            <x-select.option value="{{ $option->id }}" wire:key="team-{{ $option->id }}">{{ $option->name }}</x-select.option>
                        @endforeach
                    </x-select.group>
                @endforeach
            </x-select>

            <x-select variant="pill" wire:model.live="market" label="Market">
                <x-select.option value="">All markets</x-select.option>
                @foreach ($markets as $option)
                    <x-select.option value="{{ $option->id }}" wire:key="market-{{ $option->id }}">{{ $option->name }}</x-select.option>
                @endforeach
            </x-select>

            <x-select variant="pill" wire:model.live="tipster" label="Tipster">
                <x-select.option value="">All tipsters</x-select.option>
                @foreach ($tipsters as $option)
                    <x-select.option value="{{ $option->id }}" wire:key="tipster-{{ $option->id }}">{{ $option->name }}</x-select.option>
                @endforeach
            </x-select>

            <x-select variant="pill" wire:model.live="tag" label="Tag">
                <x-select.option value="">All tags</x-select.option>
                @foreach ($tags as $option)
                    <x-select.option value="{{ $option->id }}" wire:key="tag-{{ $option->id }}">{{ $option->name }}</x-select.option>
                @endforeach
            </x-select>

            <x-select variant="pill" wire:model.live="type" label="Bet type">
                <x-select.option value="">All bet types</x-select.option>
                @foreach ($types as $value => $label)
                    <x-select.option value="{{ $value }}" wire:key="type-{{ $value }}">{{ $label }}</x-select.option>
                @endforeach
            </x-select>

            <button
                type="button"
                wire:click="toggleFreeBets"
                aria-pressed="{{ $includesFreeBets ? 'true' : 'false' }}"
                class="flex h-11 shrink-0 items-center gap-2 rounded-full border border-line bg-surface px-4 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
                <span
                    aria-hidden="true"
                    @class([
                        'flex size-[18px] items-center justify-center rounded-[5px]',
                        'bg-accent text-accent-foreground' => $includesFreeBets,
                        'border-2 border-zinc-400' => ! $includesFreeBets,
                    ])
                >
                    @if ($includesFreeBets)
                        <flux:icon.check variant="micro" />
                    @endif
                </span>
                Include free bets
            </button>

            @if ($isFiltered)
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="h-11 shrink-0 rounded-full px-3 text-sm font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >Clear filters</button>
            @endif
        </div>

        @if (! $hasMatchingBets)
            <section class="rounded-[20px] border border-line bg-surface p-5">
                <p class="text-[15px] font-medium">{{ $isFiltered ? 'No bets match these filters' : 'No bets in this date range' }}</p>
                <p class="mt-1 text-sm text-muted">Settled bets count by the date of their event, open bets by the day they were placed.</p>

                @if ($isFiltered)
                    <flux:button size="sm" wire:click="clearFilters" class="mt-3">Clear filters</flux:button>
                @endif
            </section>
        @else
            <div class="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
                @foreach ($kpis as $kpi)
                    <div wire:key="kpi-{{ $loop->index }}" class="flex flex-col gap-1 rounded-2xl border border-line bg-surface p-4">
                        <span class="text-[13px] text-muted">{{ $kpi['label'] }}</span>
                        <span @class([
                            'text-[26px] font-semibold tracking-tight',
                            'text-profit' => $kpi['tone'] === 'profit',
                            'text-loss' => $kpi['tone'] === 'loss',
                        ])>{{ $kpi['value'] }}</span>
                        <span class="text-xs text-muted">{{ $kpi['sub'] }}</span>
                    </div>
                @endforeach
            </div>

            <section aria-labelledby="risk-heading" class="flex flex-col gap-3.5 rounded-[20px] border border-line bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="risk-heading" class="text-[17px] font-semibold">Risk</h2>
                    <span class="text-[13px] text-muted">Settled bets in event order</span>
                </div>

                @if ($totals->settledBets === 0)
                    <p class="text-sm text-muted">Risk figures show once bets in this range are settled.</p>
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ($riskTiles as $tile)
                            <div wire:key="risk-{{ $loop->index }}" class="flex min-w-0 flex-col gap-0.5 rounded-2xl border border-line p-3">
                                <span class="text-[13px] text-muted">{{ $tile['label'] }}</span>
                                <span @class([
                                    'text-xl font-semibold tracking-tight',
                                    'text-profit' => $tile['tone'] === 'profit',
                                    'text-loss' => $tile['tone'] === 'loss',
                                ])>{{ $tile['value'] }}</span>
                                @if ($tile['betId'] === null)
                                    <span class="text-xs text-muted">{{ $tile['sub'] }}</span>
                                @else
                                    <a
                                        href="{{ route('bets.show', $tile['betId']) }}"
                                        wire:navigate
                                        aria-label="{{ $tile['label'] }}: view the bet of {{ $tile['sub'] }}"
                                        class="-mb-3 flex min-h-11 items-center text-xs font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    >View bet · {{ $tile['sub'] }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="flex flex-col gap-3.5 rounded-[20px] border border-line bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-[17px] font-semibold">Profit over time</h2>

                    @if ($profitChart !== null)
                        <span class="text-[13px] text-muted">{{ $profitChart->caption }}</span>
                    @endif
                </div>

                @if ($profitChart === null)
                    <p class="text-sm text-muted">The chart shows once bets in this range are settled.</p>
                @else
                    <div class="flex gap-3">
                        <div aria-hidden="true" class="flex h-[260px] min-w-11 flex-col justify-between text-right text-xs text-muted">
                            @foreach ($profitChart->ticks as $tick)
                                <span wire:key="tick-{{ $loop->index }}" class="leading-none">{{ $tick['value'] }}</span>
                            @endforeach
                        </div>

                        <div class="flex min-w-0 grow flex-col gap-2">
                            {{-- Hovering, tapping or dragging across the chart shows the nearest period's date and cumulative profit. --}}
                            <div
                                x-data="{
                                    active: null,
                                    pick(event) {
                                        const box = this.$root.getBoundingClientRect();
                                        const periods = Number(this.$root.dataset.periods);
                                        this.active = Math.min(periods, Math.max(1, Math.round((event.clientX - box.left) / box.width * periods)));
                                    },
                                }"
                                data-periods="{{ count($profitChart->points) }}"
                                x-on:pointerdown="pick($event)"
                                x-on:pointermove="pick($event)"
                                x-on:pointerleave="if ($event.pointerType === 'mouse') active = null"
                                x-on:click.outside="active = null"
                                class="relative h-[260px] touch-pan-y"
                            >
                                <svg width="100%" height="260" viewBox="0 0 {{ $profitChart::WIDTH }} {{ $profitChart::HEIGHT }}" preserveAspectRatio="none" role="img" aria-label="{{ $profitChart->description }}" class="overflow-visible">
                                    @foreach ($profitChart->ticks as $tick)
                                        <line
                                            wire:key="grid-{{ $loop->index }}"
                                            x1="0" y1="{{ $tick['y'] }}" x2="{{ $profitChart::WIDTH }}" y2="{{ $tick['y'] }}"
                                            vector-effect="non-scaling-stroke"
                                            @class(['stroke-zinc-300 dark:stroke-zinc-600' => $tick['isZero'], 'stroke-line' => ! $tick['isZero']])
                                        />
                                    @endforeach
                                    <path d="{{ $profitChart->area }}" class="fill-accent-soft" stroke="none" />
                                    <path d="{{ $profitChart->line }}" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" class="stroke-accent" />
                                </svg>

                                @foreach ($profitChart->points as $point)
                                    <div wire:key="point-{{ $loop->index }}" aria-hidden="true" style="left: {{ $point['left'] }}; top: {{ $point['top'] }}" class="pointer-events-none absolute">
                                        <div x-show="active === {{ $loop->iteration }}" style="display: none">
                                            <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-accent"></span>
                                            <span @class([
                                                'absolute flex flex-col whitespace-nowrap rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs shadow-sm',
                                                'right-2.5' => $point['isRightHalf'],
                                                'left-2.5' => ! $point['isRightHalf'],
                                                'top-2.5' => $point['isNearTop'],
                                                'bottom-2.5' => ! $point['isNearTop'],
                                            ])>
                                                <span class="text-muted">{{ $point['label'] }}</span>
                                                <span class="font-semibold">{{ $point['value'] }}</span>
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <table class="sr-only">
                                <caption>Profit over time: {{ $profitChart->caption }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ $profitChart->periodHeading }}</th>
                                        <th scope="col">Cumulative profit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($profitChart->points as $point)
                                        <tr wire:key="point-row-{{ $loop->index }}">
                                            <th scope="row">{{ $point['label'] }}</th>
                                            <td>{{ $point['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            <div aria-hidden="true" class="relative h-4 text-xs text-muted">
                                @foreach ($profitChart->dates as $date)
                                    <span
                                        wire:key="date-{{ $loop->index }}"
                                        style="left: {{ $date['left'] }}"
                                        @class([
                                            'absolute top-0 whitespace-nowrap',
                                            '-translate-x-full' => $date['isLast'],
                                            '-translate-x-1/2' => ! $date['isLast'],
                                            'max-sm:hidden' => $date['isMinor'],
                                        ])
                                    >{{ $date['label'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </section>

            <div class="flex flex-wrap items-start gap-5">
                <section class="flex min-w-0 flex-[3_1_520px] flex-col gap-3.5 rounded-[20px] border border-line bg-surface p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2.5">
                        <h2 class="text-[17px] font-semibold">Breakdown</h2>

                        <div role="group" aria-label="Break down by" class="flex flex-wrap gap-1">
                            @foreach ($mainDimensions as $option)
                                <button
                                    type="button"
                                    wire:key="dimension-{{ $option->value }}"
                                    wire:click="$set('dimension', '{{ $option->value }}')"
                                    aria-pressed="{{ $option === $breakdownBy ? 'true' : 'false' }}"
                                    @class([
                                        'h-11 rounded-full border px-3 text-[13px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                        'border-accent bg-accent-soft font-semibold text-accent-soft-content' => $option === $breakdownBy,
                                        'border-line bg-surface font-medium text-ink hover:border-accent' => $option !== $breakdownBy,
                                    ])
                                >{{ $option->label() }}</button>
                            @endforeach

                            <flux:dropdown align="end">
                                <button
                                    type="button"
                                    @class([
                                        'flex h-11 items-center gap-1 rounded-full border px-3 text-[13px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                        'border-accent bg-accent-soft font-semibold text-accent-soft-content' => $isMoreDimension,
                                        'border-line bg-surface font-medium text-muted hover:border-accent' => ! $isMoreDimension,
                                    ])
                                >
                                    {{ $isMoreDimension ? $breakdownBy->label() : 'More' }}
                                    <flux:icon.chevron-down variant="micro" />
                                </button>

                                <flux:menu>
                                    @foreach ($moreDimensions as $option)
                                        <flux:menu.item wire:key="more-dimension-{{ $option->value }}" wire:click="$set('dimension', '{{ $option->value }}')">{{ $option->label() }}</flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] border-collapse text-sm">
                            <thead>
                                <tr class="text-right text-xs text-muted">
                                    <th scope="col" class="border-b border-line py-2 pr-2 text-left font-medium">{{ $breakdownBy->label() }}</th>
                                    @foreach ($breakdownColumns as $column)
                                        @if ($column['sort'] === null)
                                            <th scope="col" class="border-b border-line p-2 font-medium">{{ $column['label'] }}</th>
                                        @else
                                            <th
                                                scope="col"
                                                wire:key="sort-{{ $column['sort'] }}"
                                                aria-sort="{{ $column['ariaSort'] }}"
                                                @class(['border-b border-line font-medium', 'p-2' => ! $loop->last, 'py-2 pl-2' => $loop->last])
                                            >
                                                <button
                                                    type="button"
                                                    wire:click="sortBreakdown('{{ $column['sort'] }}')"
                                                    @class([
                                                        '-my-2 inline-flex min-h-11 items-center gap-0.5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                                        'font-semibold text-ink' => $column['ariaSort'] !== 'none',
                                                    ])
                                                >
                                                    {{ $column['label'] }}
                                                    @if ($column['ariaSort'] === 'ascending')
                                                        <flux:icon.chevron-up variant="micro" />
                                                    @elseif ($column['ariaSort'] === 'descending')
                                                        <flux:icon.chevron-down variant="micro" />
                                                    @endif
                                                </button>
                                            </th>
                                        @endif
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($breakdown as $row)
                                    <tr wire:key="breakdown-{{ $breakdownBy->value }}-{{ $loop->index }}" class="text-right">
                                        <th scope="row" @class([
                                            'border-b border-line py-3 pr-2 text-left font-semibold',
                                            'text-muted' => $row['isNone'] && $row['href'] === null,
                                        ])>
                                            @if ($row['href'] === null)
                                                {{ $row['label'] }}
                                            @else
                                                <a
                                                    href="{{ $row['href'] }}"
                                                    wire:navigate
                                                    aria-label="{{ $row['label'] }}: view its settled bets"
                                                    @class([
                                                        '-my-3 inline-flex min-h-11 items-center hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                                        'text-accent-content' => ! $row['isNone'],
                                                        'text-muted' => $row['isNone'],
                                                    ])
                                                >{{ $row['label'] }}</a>
                                            @endif
                                        </th>
                                        <td class="border-b border-line p-2">
                                            {{ $row['totals']->settledBets }}
                                            @if ($row['totals']->openBets > 0)
                                                <span class="block text-xs text-muted">+{{ $row['totals']->openBets }} open</span>
                                            @endif
                                        </td>
                                        <td class="border-b border-line p-2">{{ $row['totals']->turnover }}</td>
                                        <td @class([
                                            'border-b border-line p-2 font-semibold',
                                            'text-profit' => $row['totals']->profitTone === 'profit',
                                            'text-loss' => $row['totals']->profitTone === 'loss',
                                        ])>{{ $row['totals']->profit }}</td>
                                        <td @class([
                                            'border-b border-line p-2 font-semibold',
                                            'text-profit' => $row['totals']->roiTone === 'profit',
                                            'text-loss' => $row['totals']->roiTone === 'loss',
                                        ])>{{ $row['totals']->roi }}</td>
                                        <td class="border-b border-line p-2">{{ $row['totals']->strikeRate }}</td>
                                        <td class="border-b border-line p-2 text-muted">{{ $row['totals']->breakEvenStrikeRate }}</td>
                                        <td class="border-b border-line py-2 pl-2">{{ $row['totals']->averageClv }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($breakdownNote !== null)
                        <p class="text-[13px] text-muted">{{ $breakdownNote }}</p>
                    @endif
                </section>

                <div class="flex min-w-0 flex-[2_1_320px] flex-col gap-5">
                    <section class="flex flex-col gap-3 rounded-[20px] border border-line bg-surface p-5">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-[17px] font-semibold">Odds ranges</h2>
                            <p class="text-[13px] text-muted">ROI and strike rate by total odds of the bet, with the strike rate those odds need to break even</p>
                        </div>

                        <ul class="flex flex-col gap-3">
                            @foreach ($oddsBands as $band)
                                <li wire:key="band-{{ $loop->index }}" class="grid grid-cols-[80px_minmax(0,1fr)_56px_76px] items-center gap-2.5 text-[13px]">
                                    @if ($band['href'] === null)
                                        <span class="flex flex-col">
                                            <span class="font-semibold">{{ $band['label'] }}</span>
                                            <span class="text-xs text-muted">{{ $band['totals']->settledBets }} {{ Str::plural('bet', $band['totals']->settledBets) }}</span>
                                        </span>
                                    @else
                                        <a
                                            href="{{ $band['href'] }}"
                                            wire:navigate
                                            aria-label="Total odds {{ $band['label'] }}: view the {{ $band['totals']->settledBets }} settled {{ Str::plural('bet', $band['totals']->settledBets) }}"
                                            class="group flex min-h-11 flex-col justify-center focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                        >
                                            <span class="font-semibold text-accent-content group-hover:underline">{{ $band['label'] }}</span>
                                            <span class="text-xs text-muted">{{ $band['totals']->settledBets }} {{ Str::plural('bet', $band['totals']->settledBets) }}</span>
                                        </a>
                                    @endif

                                    <span aria-hidden="true" class="grid h-3.5 grid-cols-2">
                                        <span class="flex justify-end border-r border-zinc-300 dark:border-zinc-600">
                                            @if ($band['totals']->roiTone === 'loss')
                                                <span class="h-3.5 rounded-l bg-loss-fill" style="width: {{ $band['totals']->roiBar }}"></span>
                                            @endif
                                        </span>
                                        <span class="flex">
                                            @if ($band['totals']->roiTone === 'profit')
                                                <span class="h-3.5 rounded-r bg-profit-fill" style="width: {{ $band['totals']->roiBar }}"></span>
                                            @endif
                                        </span>
                                    </span>

                                    <span @class([
                                        'text-right font-semibold',
                                        'text-profit' => $band['totals']->roiTone === 'profit',
                                        'text-loss' => $band['totals']->roiTone === 'loss',
                                        'text-muted' => $band['totals']->roiTone === 'even',
                                    ])>{{ $band['totals']->roi }}</span>

                                    <span class="flex flex-col text-right">
                                        <span>{{ $band['totals']->strikeRate }}</span>
                                        @if ($band['breakEven'] !== null)
                                            <span class="text-xs whitespace-nowrap text-muted">{{ $band['breakEven'] }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="flex flex-col gap-3 rounded-[20px] border border-line bg-surface p-5">
                        <h2 class="text-[17px] font-semibold">Closing line value</h2>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[13px] text-muted">Average CLV</span>
                                <span @class([
                                    'text-[26px] font-semibold',
                                    'text-profit' => $totals->clvTone === 'profit',
                                    'text-loss' => $totals->clvTone === 'loss',
                                ])>{{ $totals->averageClv }}</span>
                            </div>

                            <div class="flex flex-col gap-0.5">
                                <span class="text-[13px] text-muted">Beat the close</span>
                                <span class="text-[26px] font-semibold">{{ $totals->beatCloseRate }}</span>
                            </div>
                        </div>

                        @if ($totals->clvBets > 0)
                            <div role="img" aria-label="{{ $totals->beatCloseBets }} of {{ $totals->clvBets }} bets beat the closing line" class="flex h-2.5 overflow-hidden rounded-full bg-line">
                                <span class="bg-accent" style="width: {{ $totals->beatCloseBar }}"></span>
                            </div>
                        @endif

                        <p class="text-[13px] text-muted">{{ $clvSummary }}</p>
                    </section>
                </div>
            </div>
        @endif
    @endif
</div>
