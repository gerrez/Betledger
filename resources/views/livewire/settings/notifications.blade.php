<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Notifications" subheading="Push notifications go to the phones where you use the BetLedger Android app.">
        <div class="space-y-6">
            @if (! $isConfigured)
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Push notifications are not set up on the server">
                    <flux:callout.text>Set <code>FCM_CREDENTIALS</code> to the Firebase service account key to send them.</flux:callout.text>
                </flux:callout>
            @endif

            @if ($devices->isEmpty())
                <section class="rounded-[20px] border border-line bg-surface p-5">
                    <p class="text-[15px] font-medium">No devices yet</p>
                    <p class="mt-1 text-sm text-muted">Sign in to the Android app and allow notifications. The phone shows up here.</p>
                </section>
            @else
                <ul class="divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
                    @foreach ($devices as $device)
                        <li wire:key="device-{{ $device->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[15px] font-medium">{{ $device->name ?? 'Android device' }}</p>
                                <p class="mt-0.5 text-sm text-muted">Last opened {{ $device->last_seen_at->diffForHumans() }}</p>
                            </div>

                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="removeDevice({{ $device->id }})"
                                data-confirm="This phone stops getting notifications until the app registers it again."
                                data-confirm-title="Remove “{{ $device->name ?? 'Android device' }}”?"
                                data-confirm-button="Remove"
                                data-confirm-danger
                            >Remove</flux:button>
                        </li>
                    @endforeach
                </ul>

                <div class="space-y-3">
                    <flux:text>Send a test to all your devices. A notification message is shown by Android while the app is in the background; a data message is always handled by the app.</flux:text>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button variant="primary" wire:click="sendTest" :disabled="! $isConfigured">Send test notification</flux:button>
                        <flux:button wire:click="sendTest(true)" :disabled="! $isConfigured">Send data message</flux:button>

                        <x-action-message class="ms-1" on="push-test-sent">Sent.</x-action-message>
                    </div>
                </div>
            @endif
        </div>
    </x-settings.layout>
</section>
