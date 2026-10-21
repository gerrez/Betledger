<div class="mx-auto flex max-w-xl flex-col gap-4">
    <header class="flex items-center gap-2">
        <a
            href="{{ route('bets.index') }}"
            wire:navigate
            aria-label="Back to bets"
            class="flex size-11 shrink-0 items-center justify-center rounded-[14px] border border-line bg-surface text-ink hover:bg-canvas"
        >
            <flux:icon.chevron-left variant="mini" />
        </a>

        <h1 class="flex-1 text-center text-xl font-semibold">{{ $detail->title }}</h1>

        <a
            href="{{ route('bets.edit', $bet) }}"
            wire:navigate
            class="flex h-11 shrink-0 items-center rounded-[14px] border border-line bg-surface px-3.5 text-[15px] font-medium text-accent-content hover:bg-canvas"
        >Edit</a>
    </header>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
    @endif

    <section class="flex flex-col gap-2 rounded-[20px] bg-surface p-5 shadow-[0_1px_2px_rgba(22,21,31,0.04),0_6px_20px_rgba(22,21,31,0.05)]">
        <div class="flex flex-wrap gap-2">
            @if ($detail->result !== null)
                <span @class([
                    'rounded-full px-2.5 py-1 text-[13px] font-semibold',
                    'bg-profit-soft text-profit' => $detail->result['tone'] === 'profit',
                    'bg-loss-soft text-loss' => $detail->result['tone'] === 'loss',
                    'bg-canvas text-ink' => $detail->result['tone'] === 'even',
                ])>{{ $detail->result['label'] }}</span>
            @else
                <span class="rounded-full bg-accent-soft px-2.5 py-1 text-[13px] font-semibold text-accent-soft-content">Open</span>
            @endif

            @if ($detail->isFreeBet)
                <span class="rounded-full bg-accent-soft px-2.5 py-1 text-[13px] font-semibold text-accent-soft-content">FREE BET</span>
            @endif
        </div>

        @if ($detail->result !== null)
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span @class([
                    'text-4xl font-semibold tracking-tight',
                    'text-profit' => $detail->result['tone'] === 'profit',
                    'text-loss' => $detail->result['tone'] === 'loss',
                ])>{{ $detail->result['profit'] }}</span>

                @if ($detail->result['profitInBase'] !== null)
                    <span class="text-[15px] text-muted">{{ $detail->result['profitInBase'] }}</span>
                @endif
            </div>

            <span class="text-sm text-muted">
                Payout {{ $detail->result['payout'] }} ·
                @if ($detail->result['isManual'])
                    <span class="font-semibold text-accent-soft-content">entered manually</span>
                @else
                    calculated
                @endif
            </span>
        @elseif ($detail->potential !== null)
            <span class="text-4xl font-semibold tracking-tight">{{ $detail->potential['payout'] }}</span>
            <span class="text-sm text-muted">
                Payout if {{ $detail->isAccumulator ? 'every open selection wins' : 'it wins' }} · profit {{ $detail->potential['profit'] }}
            </span>
        @endif
    </section>

    @foreach ($detail->selections as $selection)
        <section wire:key="selection-{{ $selection['id'] }}" class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
            <div class="flex items-start gap-3">
                <div class="flex min-w-0 grow flex-col gap-0.5">
                    <span class="text-base font-semibold">{{ $selection['pick'] }}</span>
                    <span class="text-[13px] text-muted">{{ $selection['meta'] }}</span>
                    @if ($selection['startsAt'] !== null)
                        <span class="text-[13px] text-muted">Starts {{ $selection['startsAt'] }}</span>
                    @endif
                </div>

                <span class="shrink-0 text-base font-semibold">{{ $selection['odds'] }}</span>
            </div>

            @if ($selection['closingOdds'] !== null)
                <div class="flex items-center justify-between gap-3 rounded-xl bg-canvas px-3 py-2.5 text-sm">
                    <span class="text-muted">Closing odds {{ $selection['closingOdds'] }}</span>
                    <span @class([
                        'font-semibold',
                        'text-profit' => $selection['clv']['tone'] === 'profit',
                        'text-loss' => $selection['clv']['tone'] === 'loss',
                    ])>{{ $selection['clv']['label'] }}</span>
                </div>
            @endif

            <div role="group" aria-label="Result of {{ $selection['pick'] }}" class="grid grid-cols-5 gap-1.5">
                @foreach ($selection['buttons'] as $button)
                    <button
                        type="button"
                        wire:key="selection-{{ $selection['id'] }}-result-{{ $button['result'] }}"
                        wire:click="setResult({{ $selection['id'] }}, '{{ $button['result'] }}')"
                        @if ($detail->confirmResultChange !== null && ! $button['isPressed']) wire:confirm="{{ $detail->confirmResultChange }}" @endif
                        aria-pressed="{{ $button['isPressed'] ? 'true' : 'false' }}"
                        @class([
                            'h-11 rounded-[10px] border text-[13px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                            'border-profit-fill bg-profit-soft font-bold text-profit' => $button['isPressed'] && $button['tone'] === 'won',
                            'border-loss-fill bg-loss-soft font-bold text-loss' => $button['isPressed'] && $button['tone'] === 'lost',
                            'border-zinc-400 bg-canvas font-bold text-ink' => $button['isPressed'] && $button['tone'] === 'void',
                            'border-zinc-300 bg-surface font-semibold text-ink hover:border-accent dark:border-zinc-600' => ! $button['isPressed'],
                        ])
                    >{{ $button['label'] }}</button>
                @endforeach
            </div>

            @if ($selection['hasResult'])
                <button
                    type="button"
                    wire:click="clearResult({{ $selection['id'] }})"
                    @if ($detail->confirmResultChange !== null) wire:confirm="{{ $detail->confirmResultChange }}" @endif
                    class="h-10 self-start text-sm font-medium text-accent-content hover:underline"
                >{{ $detail->isAccumulator ? 'Clear result' : 'Clear result (back to open)' }}</button>
            @endif
        </section>
    @endforeach

    @if ($detail->result !== null)
        <section class="flex flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
            @if ($editingPayout)
                <form wire:submit="savePayout" class="flex flex-col gap-2.5">
                    <flux:field>
                        <flux:label>Amount actually paid</flux:label>
                        <flux:input.group>
                            <flux:input wire:model="payout" inputmode="decimal" autocomplete="off" autofocus />
                            <flux:input.group.suffix>{{ $detail->currency }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="payout" />
                        <flux:description>Calculated: {{ $detail->result['calculatedPayout'] }}. Changing a result later replaces this amount.</flux:description>
                    </flux:field>

                    <div class="grid grid-cols-2 gap-2.5">
                        <flux:button wire:click="cancelPayout" class="h-12!">Cancel</flux:button>
                        <flux:button type="submit" variant="primary" class="h-12!">Save amount</flux:button>
                    </div>
                </form>
            @elseif ($detail->result['isManual'])
                <div class="flex items-center gap-3">
                    <span class="grow text-sm text-muted">Payout entered manually (calculated {{ $detail->result['calculatedPayout'] }})</span>
                    <flux:button size="sm" wire:click="useCalculatedPayout" class="h-10! shrink-0">Use calculated</flux:button>
                </div>
            @else
                <button
                    type="button"
                    wire:click="editPayout"
                    class="h-12 rounded-xl border border-dashed border-zinc-300 bg-surface text-[15px] font-medium text-ink hover:border-accent dark:border-zinc-600"
                >Bookmaker paid a different amount?</button>
            @endif
        </section>
    @endif

    <dl class="flex flex-col rounded-[18px] border border-line bg-surface px-4 py-1">
        @foreach ($detail->facts as $fact)
            <div wire:key="fact-{{ $loop->index }}" class="flex justify-between gap-4 border-b border-line py-3 last:border-b-0">
                <dt class="text-sm text-muted">{{ $fact['label'] }}</dt>
                <dd class="text-right text-sm font-medium">{{ $fact['value'] }}</dd>
            </div>
        @endforeach

        @if ($detail->clv !== null)
            <div class="flex justify-between gap-4 border-b border-line py-3 last:border-b-0">
                <dt class="text-sm text-muted">CLV</dt>
                <dd @class([
                    'text-right text-sm font-semibold',
                    'text-profit' => $detail->clv['tone'] === 'profit',
                    'text-loss' => $detail->clv['tone'] === 'loss',
                ])>{{ $detail->clv['percent'] }}</dd>
            </div>
        @endif

        @if ($detail->notes !== null && $detail->notes !== '')
            <div class="flex flex-col gap-1 py-3">
                <dt class="text-sm text-muted">Notes</dt>
                <dd class="whitespace-pre-line text-sm leading-relaxed">{{ $detail->notes }}</dd>
            </div>
        @endif
    </dl>

    <button
        type="button"
        wire:click="delete"
        wire:confirm="Delete this bet? This can’t be undone."
        class="h-12 rounded-xl border border-loss/30 bg-surface text-[15px] font-medium text-loss hover:bg-loss-soft"
    >Delete bet</button>
</div>
