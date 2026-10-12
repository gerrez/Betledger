<div>
    <x-page-heading title="Lists" description="The sports, competitions, teams, markets, tipsters and tags you use when you record bets." />

    <div role="tablist" aria-label="Lists" class="-mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-0.5 lg:mx-0 lg:flex-wrap lg:px-0">
        @foreach ($types as $option)
            <button
                type="button"
                role="tab"
                wire:key="tab-{{ $option->value }}"
                wire:click="$set('list', '{{ $option->value }}')"
                aria-selected="{{ $option === $type ? 'true' : 'false' }}"
                @class([
                    'h-11 shrink-0 rounded-full border px-4 text-[15px] transition-colors',
                    'border-transparent bg-accent-soft font-semibold text-accent-soft-content' => $option === $type,
                    'border-line bg-surface font-medium text-muted hover:text-ink' => $option !== $type,
                ])
            >{{ $option->label() }}</button>
        @endforeach
    </div>

    @error('delete')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :heading="$message" />
    @enderror

    @if ($groups->flatten()->isEmpty())
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No {{ $type->value }} yet</p>
            <p class="mt-1 text-sm text-muted">They are added as you record bets: type a new name in the bet form and it is saved here.</p>
        </section>
    @else
        <div class="space-y-5">
            @foreach ($groups as $sportName => $entries)
                <section wire:key="group-{{ $type->value }}-{{ $loop->index }}">
                    @if ($sportName !== '')
                        <h2 class="mb-2 px-1 text-sm font-medium text-muted">{{ $sportName }}</h2>
                    @endif

                    <ul class="divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
                        @foreach ($entries as $entry)
                            <li wire:key="{{ $type->value }}-{{ $entry->id }}" class="flex items-center gap-4 px-5 py-3">
                                <span class="min-w-0 flex-1 truncate text-[15px] font-medium">{{ $entry->name }}</span>

                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:button size="sm" wire:click="rename({{ $entry->id }})">Rename</flux:button>

                                    @if ($entry->isInUse())
                                        <span class="inline-block w-[4.5rem] text-center text-sm text-muted">In use</span>
                                    @else
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            class="w-[4.5rem]"
                                            wire:click="delete({{ $entry->id }})"
                                            wire:confirm="Delete “{{ $entry->name }}”? This can’t be undone."
                                        >Delete</flux:button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <p class="mt-3 text-sm text-muted">{{ $type->usageNote() }}</p>
    @endif

    <flux:modal wire:model.self="showRename" class="w-full md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">Rename {{ $type->singular() }}</flux:heading>

            <flux:input wire:model="name" label="Name" required autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
