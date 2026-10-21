<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-col gap-1">
            <h1 class="text-[26px] font-semibold tracking-tight lg:text-[30px]">Statistics</h1>
            <p class="text-sm text-muted">All amounts in your base currency, {{ $currency->value }}</p>
        </div>

        @if ($hasBets)
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
            <x-filter-select wire:model.live="bookmaker" label="Bookmaker">
                <option value="">All bookmakers</option>
                @foreach ($bookmakers as $option)
                    <option value="{{ $option->id }}" wire:key="bookmaker-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="sport" label="Sport">
                <option value="">All sports</option>
                @foreach ($sports as $option)
                    <option value="{{ $option->id }}" wire:key="sport-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="competition" label="Competition">
                <option value="">All competitions</option>
                @foreach ($competitions as $sportName => $options)
                    <optgroup label="{{ $sportName }}" wire:key="competition-group-{{ $loop->index }}">
                        @foreach ($options as $option)
                            <option value="{{ $option->id }}" wire:key="competition-{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="team" label="Team">
                <option value="">All teams</option>
                @foreach ($teams as $sportName => $options)
                    <optgroup label="{{ $sportName }}" wire:key="team-group-{{ $loop->index }}">
                        @foreach ($options as $option)
                            <option value="{{ $option->id }}" wire:key="team-{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="market" label="Market">
                <option value="">All markets</option>
                @foreach ($markets as $option)
                    <option value="{{ $option->id }}" wire:key="market-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="tipster" label="Tipster">
                <option value="">All tipsters</option>
                @foreach ($tipsters as $option)
                    <option value="{{ $option->id }}" wire:key="tipster-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="tag" label="Tag">
                <option value="">All tags</option>
                @foreach ($tags as $option)
                    <option value="{{ $option->id }}" wire:key="tag-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-filter-select>

            <x-filter-select wire:model.live="type" label="Bet type">
                <option value="">All bet types</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" wire:key="type-{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filter-select>

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
                <p class="mt-1 text-sm text-muted">Settled bets count by the day they were settled, open bets by the day they were placed.</p>

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
                        <table class="w-full min-w-[560px] border-collapse text-sm">
                            <thead>
                                <tr class="text-right text-xs text-muted">
                                    <th scope="col" class="border-b border-line py-2 pr-2 text-left font-medium">{{ $breakdownBy->label() }}</th>
                                    <th scope="col" class="border-b border-line p-2 font-medium">Bets</th>
                                    <th scope="col" class="border-b border-line p-2 font-medium">Turnover</th>
                                    <th scope="col" class="border-b border-line p-2 font-medium">Profit</th>
                                    <th scope="col" class="border-b border-line p-2 font-medium">ROI</th>
                                    <th scope="col" class="border-b border-line p-2 font-medium">Strike</th>
                                    <th scope="col" class="border-b border-line py-2 pl-2 font-medium">CLV</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($breakdown as $row)
                                    <tr wire:key="breakdown-{{ $breakdownBy->value }}-{{ $loop->index }}" class="text-right">
                                        <th scope="row" @class([
                                            'border-b border-line py-3 pr-2 text-left font-semibold',
                                            'text-muted' => $row['isNone'],
                                        ])>{{ $row['label'] }}</th>
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
                            <p class="text-[13px] text-muted">ROI by total odds of the bet</p>
                        </div>

                        <ul class="flex flex-col gap-3">
                            @foreach ($oddsBands as $band)
                                <li wire:key="band-{{ $loop->index }}" class="grid grid-cols-[88px_minmax(0,1fr)_64px] items-center gap-2.5 text-[13px]">
                                    <span class="flex flex-col">
                                        <span class="font-semibold">{{ $band['label'] }}</span>
                                        <span class="text-xs text-muted">{{ $band['totals']->settledBets }} {{ Str::plural('bet', $band['totals']->settledBets) }}</span>
                                    </span>

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
