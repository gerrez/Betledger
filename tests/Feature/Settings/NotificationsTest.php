<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Notifications;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\Push\FcmClient;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_only_the_users_own_devices(): void
    {
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create(['name' => 'My Pixel']);
        PushDevice::factory()->create(['name' => 'Someone else’s Galaxy']);
        $this->actingAs($user);

        Livewire::test(Notifications::class)
            ->assertSee('My Pixel')
            ->assertDontSee('Someone else’s Galaxy');
    }

    public function test_the_page_says_when_push_is_not_set_up_on_the_server(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Notifications::class)->assertSee('Push notifications are not set up on the server');
    }

    public function test_a_device_can_be_removed(): void
    {
        $user = User::factory()->create();
        $device = PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)->call('removeDevice', $device->id)->assertOk();

        $this->assertModelMissing($device);
    }

    public function test_another_users_device_cannot_be_removed(): void
    {
        $device = PushDevice::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Notifications::class)->call('removeDevice', $device->id)->assertNotFound();

        $this->assertModelExists($device);
    }

    public function test_the_test_buttons_send_a_notification_or_data_message_to_the_user(): void
    {
        Notification::fake();
        $this->app->instance(FcmClient::class, new FcmClient(['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'k']));
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)
            ->call('sendTest')
            ->assertDispatched('push-test-sent')
            ->call('sendTest', true);

        Notification::assertSentToTimes($user, TestPushNotification::class, 2);
        Notification::assertSentTo($user, TestPushNotification::class, fn (TestPushNotification $notification): bool => ! $notification->dataOnly);
        Notification::assertSentTo($user, TestPushNotification::class, fn (TestPushNotification $notification): bool => $notification->dataOnly);
    }

    public function test_nothing_is_sent_when_push_is_not_set_up(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)->call('sendTest')->assertNotDispatched('push-test-sent');

        Notification::assertNothingSent();
    }
}
