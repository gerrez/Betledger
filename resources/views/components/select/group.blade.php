@props([
    'heading',
])

<div role="group" aria-label="{{ $heading }}" {{ $attributes }}>
    <div aria-hidden="true" class="px-3 pt-2.5 pb-1 text-xs font-medium text-muted">{{ $heading }}</div>

    {{ $slot }}
</div>
