{{--
    The app's dropdown in place of a native <select>: a button that opens a list of
    <x-select.option>s (optionally in <x-select.group>s), driven by `selectMenu` in
    resources/js/app.js and bound with wire:model like a native select.
    `field` shows the label above a form control; `pill` is the filter-bar style, with the
    label for screen readers only. Labels must be unique on a page: the element ids come from them.
--}}
@props([
    'label',
    'variant' => 'field',
    'disabled' => false,
])

@php
    $id = 'select-'.Str::slug($label);
    $name = $attributes->wire('model')->value();
    $invalid = $variant === 'field' && $name !== null && $errors->has($name);
@endphp

<div
    x-data="selectMenu"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    x-on:click.outside="close()"
    x-on:resize.window="position()"
    x-on:scroll.window.capture="listbox().contains($event.target) || position()"
    @class([
        'relative',
        'shrink-0' => $variant === 'pill',
        'grid grid-cols-[minmax(0,1fr)] gap-2 has-[button:disabled]:*:data-flux-label:opacity-50' => $variant === 'field',
    ])
>
    @if ($variant === 'field')
        <flux:label id="{{ $id }}-label" x-on:click="button().focus()">{{ $label }}</flux:label>
    @endif

    <button
        type="button"
        id="{{ $id }}"
        role="combobox"
        aria-haspopup="listbox"
        aria-controls="{{ $id }}-listbox"
        aria-expanded="false"
        x-bind:aria-expanded="isOpen ? 'true' : 'false'"
        x-bind:aria-activedescendant="activeId"
        @if ($variant === 'pill') aria-label="{{ $label }}" @else aria-labelledby="{{ $id }}-label" @endif
        @if ($invalid) aria-invalid="true" @endif
        @disabled($disabled)
        x-on:click="toggle()"
        x-on:keydown="onKeydown($event)"
        data-flux-control
        {{ $attributes->whereDoesntStartWith('wire:model')->class([
            'relative flex items-center text-start whitespace-nowrap disabled:cursor-default',
            'h-11 rounded-full border border-line bg-surface pr-9 pl-4 text-sm text-ink' => $variant === 'pill',
            'h-10 w-full rounded-lg border bg-white pe-10 ps-3 text-base text-zinc-700 shadow-xs max-lg:h-11 sm:text-sm dark:bg-white/10 dark:text-zinc-300 disabled:text-zinc-500 disabled:shadow-none dark:disabled:bg-white/[7%] dark:disabled:text-zinc-400' => $variant === 'field',
            'border-zinc-200 border-b-zinc-300/80 dark:border-white/10' => $variant === 'field' && ! $invalid,
            'border-red-500' => $invalid,
        ]) }}
    >
        <span class="truncate" x-text="selectedLabel()"></span>

        <flux:icon.chevron-down variant="micro" @class([
            'pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted',
            'right-3.5' => $variant === 'pill',
            'end-3' => $variant === 'field',
        ]) />
    </button>

    <div
        id="{{ $id }}-listbox"
        role="listbox"
        aria-label="{{ $label }}"
        popover="manual"
        class="m-0 max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-xl border border-line bg-surface p-1 text-ink shadow-lg shadow-zinc-900/10 dark:shadow-black/40"
    >
        {{ $slot }}
    </div>

    @if ($variant === 'field' && $name !== null)
        <flux:error :name="$name" />
    @endif
</div>
