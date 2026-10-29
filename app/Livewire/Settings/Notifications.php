<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Notifications\Push\FcmClient;
use App\Notifications\TestPushNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Notifications')]
class Notifications extends Component
{
    /**
     * Send a test push to every device of the user: a notification message (shown by
     * Android) or a data-only message (handled by the app).
     */
    public function sendTest(bool $dataOnly = false): void
    {
        $user = $this->user();

        if (! $this->fcm()->isConfigured() || $user->pushDevices()->doesntExist()) {
            return;
        }

        $user->notify(new TestPushNotification($dataOnly));

        $this->dispatch('push-test-sent');
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
