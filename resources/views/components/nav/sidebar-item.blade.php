@props([
    'href',
    'current' => false,
])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'flex h-[42px] items-center rounded-[10px] px-3 text-[15px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'bg-accent-soft font-semibold text-accent-soft-content' => $current,
        'font-medium text-muted hover:bg-ink/5 hover:text-ink' => ! $current,
    ]) }}
>
    {{ $slot }}
</a>
