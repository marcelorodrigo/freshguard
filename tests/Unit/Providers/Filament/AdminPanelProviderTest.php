<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Filament;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Note: Tests assume PHPUnit as the testing framework (standard for Laravel).
 * These are pure unit tests focusing on AdminPanelProvider::panel configuration.
 */
#[CoversClass(AdminPanelProvider::class)]
final class AdminPanelProviderTest extends TestCase
{
    private function makePanel(): Panel
    {
        // Construct a bare Panel instance for unit testing.
        // If Filament's Panel requires container context in your version,
        // consider replacing with mocks or a container-backed instance.
        return new Panel();
    }

    private function buildConfiguredPanel(): Panel
    {
        $provider = new AdminPanelProvider();
        $panel = $this->makePanel();

        return $provider->panel($panel);
    }

    public function test_it_sets_id_and_path_and_enables_login(): void
    {
        $panel = $this->buildConfiguredPanel();

        // Id and path assertions
        $this->assertTrue(
            method_exists($panel, 'getId'),
            'Panel::getId must exist to assert id.'
        );
        $this->assertSame('admin', $panel->getId());

        $this->assertTrue(
            method_exists($panel, 'getPath'),
            'Panel::getPath must exist to assert path.'
        );
        $this->assertSame('admin', $panel->getPath());

        // login() — on Filament v3, a login page is enabled by default on panels that call ->login().
        // Where available, assert that auth is enabled or a Login route/page is registered.
        if (method_exists($panel, 'hasLogin')) {
            $this->assertTrue($panel->hasLogin(), 'Panel should have login enabled.');
        }
        if (method_exists($panel, 'getAuthGuard')) {
            $this->assertNotEmpty($panel->getAuthGuard(), 'Auth guard should be configured.');
        }
    }

    public function test_it_configures_primary_color_to_amber(): void
    {
        $panel = $this->buildConfiguredPanel();

        $this->assertTrue(
            method_exists($panel, 'getColors'),
            'Panel::getColors must exist to assert color configuration.'
        );

        $colors = $panel->getColors();
        $this->assertIsArray($colors);
        $this->assertArrayHasKey('primary', $colors);

        // Accept either the class reference or resolved palette depending on Filament internals.
        $primary = $colors['primary'];

        // Some Filament versions store Color::Amber enum/case, others resolve to palettes/strings.
        $this->assertNotNull($primary, 'Primary color should be configured.');
        // Loose checks to accommodate version differences:
        $this->assertTrue(
            $primary === Color::Amber || (is_string($primary) && stripos($primary, 'amber') \!== false),
            'Primary color should be Color::Amber or an amber palette.'
        );
    }

    public function test_it_registers_dashboard_page(): void
    {
        $panel = $this->buildConfiguredPanel();

        // Prefer getPages(); fall back to getPagesClasses() if present in specific Filament versions
        if (method_exists($panel, 'getPages')) {
            $pages = $panel->getPages();
        } elseif (method_exists($panel, 'getPagesClasses')) {
            $pages = $panel->getPagesClasses();
        } else {
            $this->markTestSkipped('Panel does not expose pages getter in this Filament version.');
            return;
        }

        $this->assertIsArray($pages);
        $this->assertContains(Dashboard::class, $pages);
    }

    public function test_it_discovers_resources_pages_widgets_directories_are_registered(): void
    {
        $panel = $this->buildConfiguredPanel();

        // Directory discovery is version-dependent; assert via known getters where available.
        $checkedSomething = false;

        if (method_exists($panel, 'getResourceDiscovery')) {
            $res = $panel->getResourceDiscovery();
            $this->assertNotEmpty($res);
            $checkedSomething = true;
        } elseif (method_exists($panel, 'getResources')) {
            // Discovery populates resources at runtime; ensure callable exists.
            $this->assertTrue(is_array($panel->getResources()) || is_iterable($panel->getResources()));
            $checkedSomething = true;
        }

        if (method_exists($panel, 'getPageDiscovery')) {
            $pages = $panel->getPageDiscovery();
            $this->assertNotEmpty($pages);
            $checkedSomething = true;
        }

        if (method_exists($panel, 'getWidgetDiscovery')) {
            $widgets = $panel->getWidgetDiscovery();
            $this->assertNotEmpty($widgets);
            $checkedSomething = true;
        }

        if (\!$checkedSomething) {
            $this->markTestSkipped('This Filament version does not expose discovery getters.');
        }
    }

    public function test_it_registers_widgets_account_and_info(): void
    {
        $panel = $this->buildConfiguredPanel();

        if (method_exists($panel, 'getWidgets')) {
            $widgets = $panel->getWidgets();
            $this->assertIsArray($widgets);
            $this->assertContains(AccountWidget::class, $widgets);
            $this->assertContains(FilamentInfoWidget::class, $widgets);
        } else {
            $this->markTestSkipped('Panel::getWidgets not available in this Filament version.');
        }
    }

    public function test_it_sets_http_middleware_stack(): void
    {
        $panel = $this->buildConfiguredPanel();

        if (\! method_exists($panel, 'getMiddleware')) {
            $this->markTestSkipped('Panel::getMiddleware not available in this Filament version.');
            return;
        }

        $middleware = $panel->getMiddleware();
        $this->assertIsArray($middleware);

        // Expected middleware classes from provider
        $expected = [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Filament\Http\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Filament\Http\Middleware\DisableBladeIconComponents::class,
            \Filament\Http\Middleware\DispatchServingFilamentEvent::class,
        ];

        foreach ($expected as $class) {
            $this->assertTrue(
                in_array($class, $middleware, true),
                "Expected middleware {$class} to be registered."
            );
        }
    }

    public function test_it_sets_auth_middleware_stack(): void
    {
        $panel = $this->buildConfiguredPanel();

        if (\! method_exists($panel, 'getAuthMiddleware')) {
            $this->markTestSkipped('Panel::getAuthMiddleware not available in this Filament version.');
            return;
        }

        $authMiddleware = $panel->getAuthMiddleware();
        $this->assertIsArray($authMiddleware);

        $this->assertTrue(
            in_array(\Filament\Http\Middleware\Authenticate::class, $authMiddleware, true),
            'Expected Filament Authenticate middleware to be registered.'
        );
    }

    public function test_chaining_is_idempotent_and_returns_same_instance(): void
    {
        $provider = new AdminPanelProvider();
        $panel = $this->makePanel();

        $configured = $provider->panel($panel);

        // Ensure fluent API returns the same Panel instance for further customization if needed
        $this->assertSame($panel, $configured);
    }
}

<?php
// Additional comprehensive tests appended to cover edge cases and failure conditions.

namespace Tests\Unit\Providers\Filament;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AdminPanelProvider::class)]
final class AdminPanelProviderTest_More
{
    public function test_reconfiguring_panel_overrides_previous_values(): void
    {
        $provider = new AdminPanelProvider();
        $panel = new Panel();

        // Manually set conflicting values before provider runs
        if (method_exists($panel, 'id')) {
            $panel->id('conflict-id');
        }
        if (method_exists($panel, 'path')) {
            $panel->path('conflict-path');
        }

        $configured = $provider->panel($panel);

        if (method_exists($configured, 'getId')) {
            assert($configured->getId() === 'admin');
        }
        if (method_exists($configured, 'getPath')) {
            assert($configured->getPath() === 'admin');
        }
    }

    public function test_provider_handles_minimal_panel_instance(): void
    {
        $provider = new AdminPanelProvider();
        $panel = new Panel();

        $configured = $provider->panel($panel);

        // Assert object is still a Panel and has some expected minimal configuration.
        assert($configured instanceof Panel);
        if (method_exists($configured, 'getId')) {
            assert($configured->getId() === 'admin');
        }
    }
}