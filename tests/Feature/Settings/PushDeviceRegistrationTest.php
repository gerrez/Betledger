<?php

namespace Tests\Feature\Settings;

use App\Http\Controllers\PushDeviceController;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushDeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_register_a_device(): void
    {
        $this->post(route('push-devices.store'), ['token' => 'abc', 'platform' => 'android'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_the_app_registers_its_token_for_the_signed_in_user(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-devices.store'), ['token' => 'token-1', 'platform' => 'android', 'name' => 'Google Pixel 8'])
            ->assertNoContent()
            ->assertSessionHas(PushDeviceController::SESSION_KEY, 'token-1');

        $device = $user->pushDevices()->sole();
        $this->assertSame('token-1', $device->token);
        $this->assertSame('android', $device->platform);
        $this->assertSame('Google Pixel 8', $device->name);
        $this->assertTrue($device->last_seen_at->equalTo(now()));
    }

    public function test_registering_the_same_token_again_updates_the_device(): void
    {
        $user = User::factory()->create();
        $device = PushDevice::factory()->for($user)->create(['token' => 'token-1', 'name' => 'Old name', 'last_seen_at' => now()->subWeek()]);

        $this->actingAs($user)
            ->postJson(route('push-devices.store'), ['token' => 'token-1', 'platform' => 'android', 'name' => 'New name'])
            ->assertNoContent();

        $this->assertDatabaseCount('push_devices', 1);
        $device->refresh();
        $this->assertSame('New name', $device->name);
        $this->assertTrue($device->last_seen_at->isToday());
    }

    public function test_a_token_moves_to_the_account_that_signed_in_on_the_phone(): void
    {
        $previous = User::factory()->create();
        PushDevice::factory()->for($previous)->create(['token' => 'shared-phone']);
        $current = User::factory()->create();

        $this->actingAs($current)
            ->postJson(route('push-devices.store'), ['token' => 'shared-phone', 'platform' => 'android'])
            ->assertNoContent();

        $this->assertSame(0, $previous->pushDevices()->count());
        $this->assertSame('shared-phone', $current->pushDevices()->sole()->token);
    }

    public function test_the_token_and_platform_are_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('push-devices.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'platform']);

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_only_android_devices_can_register(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('push-devices.store'), ['token' => 'token-1', 'platform' => 'ios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform' => 'The selected platform is invalid.']);
    }

    public function test_logging_out_forgets_the_device_registered_in_that_session(): void
    {
        $user = User::factory()->create();
        $otherPhone = PushDevice::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('push-devices.store'), ['token' => 'this-phone', 'platform' => 'android'])
            ->assertNoContent();

        $this->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame([$otherPhone->id], $user->pushDevices()->pluck('id')->all());
    }
}
