<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Reset Account') }}</flux:heading>
        <flux:subheading>{{ __('Delete all of your bets and start over, keeping your account and settings') }}</flux:subheading>
    </div>

    <div class="flex items-center gap-4">
        <flux:modal.trigger name="confirm-account-reset">
            <flux:button variant="danger" :disabled="$betCount === 0">{{ __('Reset Account') }}</flux:button>
        </flux:modal.trigger>

        <x-action-message on="account-reset">
            {{ __('All bets deleted.') }}
        </x-action-message>
    </div>

    @if ($betCount === 0)
        <flux:text>{{ __('You have no bets, so there is nothing to reset.') }}</flux:text>
    @endif

    <flux:modal name="confirm-account-reset" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="resetAccount" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to reset your account?') }}</flux:heading>
            </div>

            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('This cannot be undone')">
                <flux:callout.text>
                    {{ $betCount === 1 ? __('Your 1 bet will be permanently deleted,') : __('All :count of your bets will be permanently deleted,', ['count' => $betCount]) }}
                    {{ __('including their selections, results and tags. Your statistics will be empty, and bankroll balances will only include your deposits and withdrawals.') }}
                </flux:callout.text>
                <flux:callout.text>
                    {{ __('Your account, bookmakers, lists, bankroll deposits and withdrawals, settings and API tokens are kept.') }}
                </flux:callout.text>
            </flux:callout>

            <div class="space-y-2">
                <flux:text>{{ __('The CSV export is the only backup of your bets: once they are deleted, they cannot be recovered.') }}</flux:text>
                <flux:button icon="arrow-down-tray" :href="route('settings.export.download')">{{ __('Download your bets first') }}</flux:button>
            </div>

            <flux:input wire:model="password" id="reset-account-password" label="{{ __('Enter your password to confirm') }}" type="password" name="password" />

            <div class="flex justify-end space-x-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ __('Delete all bets') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
