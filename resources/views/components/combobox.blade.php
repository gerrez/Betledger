{{--
    A text input with suggestions fetched while typing, driven by `combobox` in
    resources/js/app.js; the list looks like <x-select>'s. Other attributes (wire:model,
    label, placeholder, …) go to the <flux:input>, `class` to the wrapper.
    `search`: a JS expression returning a promise of names for `query`, the typed text
    (e.g. "$wire.suggest('teams', 'Football', query)").
    `on-pick`: a JS expression run after a name is picked (`$event.detail.value`).
    `adds-on-enter`: Enter always picks (the highlighted option, else the typed name), for
    inputs that add to a list; otherwise Enter on a closed list keeps its default.
--}}
@props([
    'id',
    'search',
    'listLabel',
    'label' => null,
    'onPick' => null,
    'addsOnEnter' => false,
])

<div
    x-data="combobox((query) => {{ $search }}, { addsOnEnter: @js((bool) $addsOnEnter) })"
    @if ($onPick !== null) x-on:combobox-pick="{{ $onPick }}" @endif
    x-on:click.outside="close()"
    x-on:resize.window="position()"
    x-on:scroll.window.capture="listbox().contains($event.target) || position()"
    {{ $attributes->only('class')->class('relative') }}
>
    <flux:input
        :attributes="$attributes->except('class')"
        :label="$label"
        id="{{ $id }}"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="{{ $id }}-listbox"
        aria-expanded="false"
        x-bind:aria-expanded="isOpen ? 'true' : 'false'"
        x-bind:aria-activedescendant="activeId()"
        autocomplete="off"
        x-on:input="onInput($event)"
        x-on:keydown="onKeydown($event)"
        x-on:click="isOpen || refresh(0)"
        x-on:blur="close()"
    />

    <div
        id="{{ $id }}-listbox"
        role="listbox"
        aria-label="{{ $listLabel }}"
        popover="manual"
        x-on:mousedown.prevent
        class="m-0 max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-xl border border-line bg-surface p-1 text-ink shadow-lg shadow-zinc-900/10 dark:shadow-black/40"
    >
        <template x-for="(option, index) in isOpen ? options() : []" x-bind:key="index + ':' + option.value">
            <div
                role="option"
                x-bind:id="optionId(index)"
                aria-selected="false"
                x-bind:aria-selected="activeIndex === index ? 'true' : 'false'"
                x-bind:data-active="activeIndex === index"
                x-on:click="choose(index)"
                x-on:mouseenter="activeIndex = index"
                class="flex min-h-11 cursor-default items-center gap-3 rounded-lg px-3 py-2 text-[15px] select-none data-active:bg-accent-soft data-active:text-accent-soft-content lg:min-h-9 lg:text-sm"
            >
                <template x-if="option.isNew">
                    <flux:icon.plus variant="micro" class="shrink-0 text-muted" />
                </template>
                <span class="flex-1 truncate" x-text="option.isNew ? `Create ‘${option.value}’` : option.value"></span>
            </div>
        </template>
    </div>
</div>
