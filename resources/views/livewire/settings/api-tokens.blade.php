<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="API tokens" subheading="Scripts and apps use a token to record bets on your account through the API. Anyone with a token can add bets for you, so keep it secret and revoke it when it’s no longer needed.">
        <form wire:submit="createToken" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Token name" placeholder="e.g. Betting spreadsheet" autocomplete="off" maxlength="255" />

            <flux:button variant="primary" type="submit">Create token</flux:button>
        </form>

        @if ($plainTextToken !== null)
            <div class="mb-6 space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="new-token">
                <flux:heading>Copy your new token</flux:heading>
                <flux:text>It is only shown now. If you lose it, revoke it and create a new one.</flux:text>
                <flux:input :value="$plainTextToken" readonly copyable aria-label="New API token" input:class="font-mono" />
            </div>
        @endif

        <section class="space-y-3">
            <flux:heading>Your tokens</flux:heading>

            @forelse ($tokens as $token)
                <div class="flex min-h-11 items-center justify-between gap-4 border-b border-zinc-200 py-2 last:border-b-0 dark:border-zinc-700" wire:key="token-{{ $token->id }}">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ $token->name }}</p>
                        <flux:text size="sm">
                            Created {{ $token->created_at?->format('j M Y') }} ·
                            {{ $token->last_used_at === null ? 'Never used' : 'Last used '.$token->last_used_at->diffForHumans() }}
                        </flux:text>
                    </div>

                    <flux:button
                        variant="ghost"
                        size="sm"
                        wire:click="revokeToken({{ $token->id }})"
                        data-confirm="Requests using “{{ $token->name }}” are refused from now on. This can’t be undone."
                        data-confirm-title="Revoke this token?"
                        data-confirm-button="Revoke"
                        data-confirm-danger
                    >Revoke</flux:button>
                </div>
            @empty
                <flux:text>You have no API tokens.</flux:text>
            @endforelse
        </section>

        <section class="mt-8 space-y-2">
            <flux:heading>Using the API</flux:heading>
            <flux:text>
                Send the token as <code>Authorization: Bearer &lt;token&gt;</code> with
                <code>Accept: application/json</code>. Record a bet with
                <code>POST {{ route('api.v1.bets.store') }}</code>; the fields are the same as on the
                <flux:link :href="route('bets.create')" wire:navigate>New bet</flux:link> form.
            </flux:text>
        </section>
    </x-settings.layout>
</section>
