<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Notifications;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\Push\FcmClient;
use App\Notifications\Push\PushChannel;
use App\Notifications\Push\PushReport;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_test_buttons_send_a_notification_or_data_message_and_say_how_many_devices_got_it(): void
    {
        $this->app->instance(FcmClient::class, new FcmClient(['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'k']));
        $this->mock(PushChannel::class)
            ->shouldReceive('send')
            ->twice()
            ->withArgs(fn (User $user, TestPushNotification $notification): bool => $user->is(auth()->user()))
            ->andReturn(new PushReport(sent: 2), new PushReport(sent: 1, removed: 1));
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)
            ->call('sendTest')
            ->assertSet('testFailed', false)
            ->assertSee('Sent to 2 devices.')
            ->call('sendTest', true)
            ->assertSee('Sent to 1 device. Removed 1 device where the app is no longer installed.');
    }

    public function test_a_failed_test_shows_why(): void
    {
        $this->app->instance(FcmClient::class, new FcmClient(['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'k']));
        $this->mock(PushChannel::class)
            ->shouldReceive('send')
            ->once()
            ->andReturn(new PushReport(failed: 1, errors: ['Firebase answered 403: Permission denied.']));
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)
            ->call('sendTest')
            ->assertSet('testFailed', true)
            ->assertSee('Failed for 1 device: Firebase answered 403: Permission denied.')
            ->assertDontSee('Sent to');
    }

    public function test_nothing_is_sent_when_push_is_not_set_up(): void
    {
        $this->mock(PushChannel::class)->shouldNotReceive('send');
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Notifications::class)->call('sendTest')->assertSet('testResult', null);
    }

    public function test_unusable_credentials_show_the_reason_instead_of_breaking_the_page(): void
    {
        config(['services.fcm.credentials' => '/does/not/exist.json']);
        $this->app->forgetInstance(FcmClient::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.notifications'))
            ->assertOk()
            ->assertSee("FCM_CREDENTIALS: can't read the file /does/not/exist.json.");
    }
}
