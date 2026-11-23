{{-- The KPI tiles of one bankroll row or the total: expects $figures (FiguresDisplay). --}}
<dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
    <div class="flex flex-col gap-1 rounded-2xl border border-line px-4 py-3">
        <dt class="text-[13px] text-muted">Bets</dt>
        <dd class="text-[17px] font-semibold tabular-nums">{{ $figures->bets }}</dd>
    </div>

    <div class="flex flex-col gap-1 rounded-2xl border border-line px-4 py-3">
        <dt class="text-[13px] text-muted">In open bets</dt>
        <dd class="text-[17px] font-semibold tabular-nums">{{ $figures->openStake }}</dd>
    </div>

    <div class="flex flex-col gap-1 rounded-2xl border border-line px-4 py-3">
        <dt class="text-[13px] text-muted">Turnover</dt>
        <dd class="text-[17px] font-semibold tabular-nums">{{ $figures->turnover }}</dd>
    </div>

    <div class="flex flex-col gap-1 rounded-2xl border border-line px-4 py-3">
        <dt class="text-[13px] text-muted">ROI</dt>
        <dd @class([
            'text-[17px] font-semibold tabular-nums',
            'text-profit' => $figures->roiTone === 'profit',
            'text-loss' => $figures->roiTone === 'loss',
        ])>{{ $figures->roi }}</dd>
    </div>

    <div class="flex flex-col gap-1 rounded-2xl border border-line px-4 py-3">
        <dt class="text-[13px] text-muted">Profit</dt>
        <dd @class([
            'text-[17px] font-semibold tabular-nums',
            'text-profit' => $figures->profitTone === 'profit',
            'text-loss' => $figures->profitTone === 'loss',
        ])>{{ $figures->profit }}</dd>
    </div>
</dl>
