@props([
    'href',
    'icon',
    'current' => false,
])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'flex h-[52px] w-[60px] flex-col items-center justify-center gap-0.5 rounded-xl text-[11px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'font-semibold text-accent-content' => $current,
        'font-medium text-muted hover:text-ink' => ! $current,
    ]) }}
>
    <flux:icon :icon="$icon" variant="outline" class="size-[22px]" />
    {{ $slot }}
</a>
