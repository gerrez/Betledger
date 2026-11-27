<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Deleted bets" :subheading="'Bets you deleted in the last '.$daysKept.' days. They are left out of your bets, statistics and bankroll until you restore them, and are deleted for good after '.$daysKept.' days.'">
        @if ($restoredBet !== null)
            <flux:callout variant="success" icon="check-circle" class="my-6" heading="Bet restored." wire:key="restored-{{ $restoredBet->id }}">
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('bets.show', $restoredBet)" wire:navigate>View bet</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        @if ($notice !== null)
            <flux:callout variant="success" icon="check-circle" class="my-6" :heading="$notice" />
        @endif

        @if ($paginator->total() > 0)
            <div class="my-6 flex flex-wrap items-center justify-between gap-3">
                <flux:text>
                    {{ $paginator->total() === 1 ? '1 deleted bet.' : $paginator->total().' deleted bets.' }}
                    Deleted imported bets still count as imported, so an import skips their orders until they are deleted for good.
                </flux:text>

                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="forceDeleteAll"
                    data-confirm="Their selections, results and tags are deleted too. This can’t be undone."
                    data-confirm-title="Delete {{ $paginator->total() === 1 ? '1 bet' : $paginator->total().' bets' }} for good?"
                    data-confirm-button="Delete all for good"
                    data-confirm-danger
                >Delete all for good</flux:button>
            </div>
        @endif

        <section class="my-6 space-y-3">
            @forelse ($bets as $bet)
                <div class="flex flex-col gap-3 border-b border-zinc-200 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700" wire:key="deleted-bet-{{ $bet['id'] }}">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ $bet['title'] }}</p>
                        <flux:text size="sm" class="truncate">{{ $bet['meta'] }}</flux:text>
                        <flux:text size="sm">{{ $bet['amounts'] }}</flux:text>
                        <flux:text size="sm">{{ $bet['deleted'] }}</flux:text>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <flux:button size="sm" variant="primary" wire:click="restore({{ $bet['id'] }})">Restore</flux:button>
                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="forceDelete({{ $bet['id'] }})"
                            data-confirm="Its selections, results and tags are deleted too. This can’t be undone."
                            data-confirm-title="Delete “{{ $bet['title'] }}” for good?"
                            data-confirm-button="Delete for good"
                            data-confirm-danger
                        >Delete for good</flux:button>
                    </div>
                </div>
            @empty
                <flux:text>You have no deleted bets.</flux:text>
            @endforelse
        </section>

        @if ($paginator->hasPages())
            <flux:pagination :paginator="$paginator" />
        @endif
    </x-settings.layout>
</section>
