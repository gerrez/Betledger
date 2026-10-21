<div class="mt-4 flex flex-col gap-6">
    <div class="text-center text-sm text-muted">
        {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="font-medium text-center text-sm text-profit">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="flex flex-col items-center justify-between space-y-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full">
            {{ __('Resend verification email') }}
        </flux:button>

        <button
            wire:click="logout"
            type="submit"
            class="min-h-11 rounded-md text-sm text-muted underline hover:text-ink"
        >
            {{ __('Log out') }}
        </button>
    </div>
</div>
