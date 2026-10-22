{{-- One of a selection's result buttons, from BetDetail::resultButtons(). `confirm` is the warning to confirm before the result changes, while the payout was entered by hand. --}}
@props([
    'button',
    'confirm' => null,
])

<button
    type="button"
    aria-pressed="{{ $button['isPressed'] ? 'true' : 'false' }}"
    @if ($confirm !== null && ! $button['isPressed']) data-confirm="{{ $confirm }}" data-confirm-title="Replace your payout?" data-confirm-button="Change result" @endif
    {{ $attributes->class([
        'h-11 rounded-[10px] border text-[13px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'border-profit-fill bg-profit-soft font-bold text-profit' => $button['isPressed'] && $button['tone'] === 'won',
        'border-loss-fill bg-loss-soft font-bold text-loss' => $button['isPressed'] && $button['tone'] === 'lost',
        'border-zinc-400 bg-canvas font-bold text-ink' => $button['isPressed'] && $button['tone'] === 'void',
        'border-zinc-300 bg-surface font-semibold text-ink hover:border-accent dark:border-zinc-600' => ! $button['isPressed'],
    ]) }}
>{{ $button['label'] }}</button>
