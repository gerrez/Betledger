@props([
    'label',
])

<div class="relative shrink-0">
    <select
        aria-label="{{ $label }}"
        {{ $attributes->class('h-11 appearance-none rounded-full border border-line bg-surface pr-9 pl-4 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent') }}
    >
        {{ $slot }}
    </select>

    <flux:icon.chevron-down variant="micro" class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-muted" />
</div>
