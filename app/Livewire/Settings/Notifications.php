<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Notifications\Push\FcmClient;
use App\Notifications\Push\PushChannel;
use App\Notifications\TestPushNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Notifications')]
class Notifications extends Component
{
    /**
     * What the last test did: how many devices got it and why the others didn't.
     */
    public ?string $testResult = null;

    public bool $testFailed = false;

    /**
     * Send a test push to every device of the user: a notification message (shown by
     * Android) or a data-only message (handled by the app). Sent through the channel
     * directly, not `notify()`, to report what Firebase answered.
     */
    public function sendTest(bool $dataOnly = false): void
    {
        $user = $this->user();

        if (! $this->fcm()->isConfigured() || $user->pushDevices()->doesntExist()) {
            return;
        }

        $report = app(PushChannel::class)->send($user, new TestPushNotification($dataOnly));

        $lines = [];

        if ($report->sent > 0) {
            $lines[] = 'Sent to '.$report->sent.' '.Str::plural('device', $report->sent).'.';
        }

        if ($report->removed > 0) {
            $lines[] = 'Removed '.$report->removed.' '.Str::plural('device', $report->removed).' where the app is no longer installed.';
        }

        if ($report->failed > 0) {
            $lines[] = 'Failed for '.$report->failed.' '.Str::plural('device', $report->failed).': '.implode(' ', $report->errors);
        }

        $this->testResult = implode(' ', $lines);
        $this->testFailed = $report->sent === 0;
    }

    /**
     * Stop sending notifications to one of the user's devices.
     */
    public function removeDevice(int $id): void
    {
        $device = $this->user()->pushDevices()->findOrFail($id);

        $this->authorize('delete', $device);

        $device->delete();
    }

    public function render(): View
    {
        return view('livewire.settings.notifications', [
            'devices' => $this->user()->pushDevices()->latest('last_seen_at')->get(),
            'isConfigured' => $this->fcm()->isConfigured(),
            'configurationError' => $this->fcm()->configurationError,
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function fcm(): FcmClient
    {
        return app(FcmClient::class);
    }
}
