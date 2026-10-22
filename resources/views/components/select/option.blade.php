@aware([
    'label',
])

@props([
    'value',
])

<div
    role="option"
    id="select-{{ Str::slug($label) }}-option-{{ (string) $value === '' ? 'none' : Str::slug((string) $value) }}"
    data-value="{{ $value }}"
    aria-selected="false"
    x-bind:aria-selected="isSelected($el) ? 'true' : 'false'"
    x-bind:data-active="activeId === $el.id"
    x-on:click="choose($el)"
    x-on:mouseenter="activeId = $el.id"
    {{ $attributes->class('group flex min-h-11 cursor-default items-center gap-3 rounded-lg px-3 py-2 text-[15px] whitespace-nowrap select-none aria-selected:font-semibold data-active:bg-accent-soft data-active:text-accent-soft-content lg:min-h-9 lg:text-sm') }}
>
    <span class="flex-1">{{ $slot }}</span>

    <flux:icon.check variant="micro" class="invisible shrink-0 text-accent-content group-aria-selected:visible" />
</div>
