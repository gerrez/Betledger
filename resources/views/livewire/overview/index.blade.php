<div class="flex max-w-2xl flex-col gap-5">
    <div class="flex items-center justify-between gap-3">
        <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-muted lg:hidden">BetLedger</span>
            <h1 class="text-[26px] font-semibold tracking-tight lg:text-[30px]">Overview</h1>
        </div>

        @if ($hasBets)
            <x-select variant="pill" wire:model.live="month" label="Month" class="font-medium">
                @foreach ($months as $option)
                    <x-select.option value="{{ $option['value'] }}" wire:key="month-{{ $option['value'] }}">{{ $option['label'] }}</x-select.option>
                @endforeach
            </x-select>
        @endif
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
    @endif

    @if (! $hasBets)
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bets yet</p>
            <p class="mt-1 text-sm text-muted">Record the bets you place, settle them when the results are in, and your month at a glance shows up here.</p>

            <flux:button :href="route('bets.create')" wire:navigate variant="primary" icon="plus" class="mt-4">New bet</flux:button>
        </section>
    @else
        <section class="flex flex-col gap-3.5 rounded-[20px] bg-surface p-5 shadow-[0_1px_2px_rgba(22,21,31,0.04),0_6px_20px_rgba(22,21,31,0.05)] dark:border dark:border-line">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm text-muted">{{ $profitLabel }}</h2>

                @if ($totals->roi !== $totals::NONE)
                    <span @class([
                        'rounded-full px-2.5 py-1 text-[13px] font-semibold',
                        'bg-profit-soft text-profit' => $totals->roiTone === 'profit',
                        'bg-loss-soft text-loss' => $totals->roiTone === 'loss',
                        'bg-line text-muted' => $totals->roiTone === 'even',
                    ])>ROI {{ $totals->roi }}</span>
                @endif
            </div>

            <div class="flex items-baseline gap-1.5">
                <span @class([
                    'text-[38px] leading-tight font-semibold tracking-tight',
                    'text-profit' => $totals->profitTone === 'profit',
                    'text-loss' => $totals->profitTone === 'loss',
                ])>{{ $totals->profit }}</span>
                <span class="text-base font-medium text-muted">{{ $symbol }}</span>
            </div>

            @if ($sparkline !== null)
                <svg width="100%" height="64" viewBox="0 0 {{ $sparkline::WIDTH }} {{ $sparkline::HEIGHT }}" preserveAspectRatio="none" role="img" aria-label="{{ $sparkline->description }}">
                    <path d="{{ $sparkline->area }}" class="fill-accent-soft" stroke="none" />
                    <path d="{{ $sparkline->line }}" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" class="stroke-accent" />
                </svg>
            @endif

            <p class="text-[13px] text-muted">{{ $summary }}</p>
        </section>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($kpis as $kpi)
                <div wire:key="kpi-{{ $loop->index }}" class="flex flex-col gap-1 rounded-2xl border border-line bg-surface px-4 py-3.5">
                    <span class="text-[13px] text-muted">{{ $kpi['label'] }}</span>
                    <span @class([
                        'text-[22px] font-semibold tracking-tight',
                        'text-profit' => $kpi['tone'] === 'profit',
                        'text-loss' => $kpi['tone'] === 'loss',
                    ])>{{ $kpi['value'] }}</span>
                    <span class="text-xs text-muted">{{ $kpi['sub'] }}</span>
                </div>
            @endforeach
        </div>

        <section class="flex flex-col gap-2.5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-[17px] font-semibold">Open bets <span class="font-medium text-muted">· {{ $openBets }}</span></h2>

                @if ($openBets > 0)
                    <a
                        href="{{ route('bets.index') }}"
                        wire:navigate
                        class="flex min-h-11 items-center text-sm font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >Settle →</a>
                @endif
            </div>

            @forelse ($previews as $preview)
                <a
                    href="{{ route('bets.show', $preview->id) }}"
                    wire:navigate
                    wire:key="open-bet-{{ $preview->id }}"
                    class="flex items-center gap-3 rounded-2xl border border-line bg-surface px-4 py-3.5 text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    <div class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="truncate text-[15px] font-semibold">{{ $preview->title }}</span>
                        <span class="text-[13px] text-muted">{{ $preview->meta }}</span>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-0.5">
                        <span class="text-[15px] font-semibold">{{ $preview->stake }}</span>
                        <span class="text-[13px] text-muted">@ {{ $preview->totalOdds }}</span>
                    </div>
                </a>
            @empty
                <p class="rounded-2xl border border-line bg-surface px-4 py-3.5 text-sm text-muted">No open bets. Bets you record show up here until they are settled.</p>
            @endforelse

            @if ($openBets > count($previews))
                <a
                    href="{{ route('bets.index') }}"
                    wire:navigate
                    class="self-start py-2.5 text-sm font-medium text-accent-content hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >All {{ $openBets }} open bets</a>
            @endif
        </section>
    @endif
</div>
