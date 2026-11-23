<?php

namespace Tests\Feature\Settings;

use App\Domain\LocalTime;
use App\Livewire\Auth\Register;
use App\Livewire\Settings\BaseCurrency;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The user's time zone: chosen on Settings → Currency and time zone, and taken from the
 * browser when registering.
 */
class TimeZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_current_time_zone(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']));

        $this->get(route('settings.currency'))
            ->assertOk()
            ->assertSee(['Currency and time zone', 'Time zone', 'America/Argentina/Buenos Aires']);

        Livewire::test(BaseCurrency::class)->assertSet('timezone', 'America/Argentina/Buenos_Aires');
    }

    public function test_the_settings_menu_names_the_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.profile'))->assertOk()->assertSee('Currency and time zone');
    }

    public function test_the_time_zone_can_be_changed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('timezone', 'Europe/Copenhagen')
            ->call('updateTimezone')
            ->assertHasNoErrors()
            ->assertDispatched('timezone-updated');

        $this->assertSame('Europe/Copenhagen', $user->refresh()->timezone);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidZones(): array
    {
        return [
            'unknown' => ['Mars/Olympus_Mons', 'Choose a time zone from the list.'],
            'legacy name' => ['Europe/Kiev', 'Choose a time zone from the list.'],
            'empty' => ['', 'Choose a time zone.'],
        ];
    }

    #[DataProvider('invalidZones')]
    public function test_the_time_zone_must_be_one_from_the_list(string $timezone, string $message): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('timezone', $timezone)
            ->call('updateTimezone')
            ->assertHasErrors(['timezone' => $message]);

        $this->assertSame('Europe/Copenhagen', $user->refresh()->timezone);
    }

    public function test_the_time_zone_stays_editable_once_the_user_has_bets(): void
    {
        $user = User::factory()->create();
        Bet::factory()->forBookmaker(Bookmaker::factory()->for($user)->create())->single()->create();
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->assertSee('The base currency can’t be changed once you have recorded bets')
            ->set('timezone', 'Europe/Copenhagen')
            ->call('updateTimezone')
            ->assertHasNoErrors();

        $this->assertSame('Europe/Copenhagen', $user->refresh()->timezone);
    }

    public function test_saving_the_time_zone_changes_only_the_logged_in_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->actingAs($user);

        Livewire::test(BaseCurrency::class)
            ->set('timezone', 'Europe/Copenhagen')
            ->call('updateTimezone')
            ->assertHasNoErrors();

        $this->assertSame('Europe/Copenhagen', $user->refresh()->timezone);
        $this->assertSame('Asia/Tokyo', $other->refresh()->timezone);
    }

    public function test_the_dropdown_offers_exactly_the_zones_the_rule_accepts(): void
    {
        $identifiers = LocalTime::identifiers();

        $this->assertContains('UTC', $identifiers);
        $this->assertContains('Europe/Copenhagen', $identifiers);
        $this->assertNotContains('Europe/Kiev', $identifiers);
        $this->assertTrue(Validator::make(['zones' => $identifiers], ['zones.*' => 'timezone:all'])->passes());
        $this->assertTrue(Validator::make(['zone' => 'Europe/Kiev'], ['zone' => 'timezone:all'])->fails());
    }

    public function test_registration_stores_the_browsers_time_zone(): void
    {
        $this->register('Europe/Copenhagen');

        $this->assertSame('Europe/Copenhagen', User::sole()->timezone);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableBrowserZones(): array
    {
        return [
            'missing' => [''],
            'unknown' => ['Mars/Olympus_Mons'],
            'legacy name from an older browser' => ['Europe/Kiev'],
        ];
    }

    #[DataProvider('unusableBrowserZones')]
    public function test_registration_falls_back_to_utc_without_an_error(string $timezone): void
    {
        $this->register($timezone);

        $this->assertSame('UTC', User::sole()->timezone);
    }

    public function test_registration_reads_the_zone_from_the_browser(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('$wire.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone', escape: false);
    }

    private function register(string $timezone): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('timezone', $timezone)
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
    }
}
