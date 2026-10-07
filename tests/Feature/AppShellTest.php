<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every page of the app (everything behind the login).
     *
     * @return array<string, array{string}>
     */
    public static function appRoutes(): array
    {
        return [
            'overview' => ['dashboard'],
            'bets' => ['bets.index'],
            'new bet' => ['bets.create'],
            'statistics' => ['statistics'],
            'bookmakers' => ['bookmakers.index'],
            'lists' => ['lists.index'],
            'settings profile' => ['settings.profile'],
            'settings password' => ['settings.password'],
            'settings appearance' => ['settings.appearance'],
        ];
    }

    #[DataProvider('appRoutes')]
    public function test_guests_are_redirected_to_the_login_page(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('appRoutes')]
    public function test_authenticated_users_see_the_page_with_the_app_navigation(string $routeName): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route($routeName))
            ->assertOk()
            ->assertSee('aria-label="Main"', escape: false)
            ->assertSee(route('dashboard'))
            ->assertSee(route('bets.index'))
            ->assertSee(route('bets.create'))
            ->assertSee(route('statistics'))
            ->assertSee(route('settings.profile'));
    }

    public function test_the_current_page_is_marked_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('statistics'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Statistics · '.config('app.name').'</title>', $html);
        $this->assertMatchesRegularExpression($this->currentLinkPattern('statistics'), $html);
        $this->assertDoesNotMatchRegularExpression($this->currentLinkPattern('bets.index'), $html);
    }

    private function currentLinkPattern(string $routeName): string
    {
        return '/href="'.preg_quote(route($routeName), '/').'"\s+wire:navigate\s+aria-current="page"/';
    }
}
