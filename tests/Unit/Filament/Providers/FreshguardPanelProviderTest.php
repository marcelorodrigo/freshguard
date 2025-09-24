<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Providers;

use App\Providers\Filament\FreshguardPanelProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Testing library/framework: PHPUnit + Mockery.
 * These tests validate the chained configuration performed by FreshguardPanelProvider::panel().
 */
final class FreshguardPanelProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_panel_configuration_is_applied_in_order(): void
    {
        $panel = m::mock(Panel::class);

        // Chain all calls with expected arguments, always returning self for fluent API.
        $panel->shouldReceive('default')->once()->andReturnSelf();
        $panel->shouldReceive('id')->once()->with('freshguard')->andReturnSelf();
        $panel->shouldReceive('path')->once()->with('freshguard')->andReturnSelf();
        $panel->shouldReceive('login')->once()->andReturnSelf();

        $panel->shouldReceive('colors')->once()->with(m::on(function (array $colors) {
            return array_key_exists('primary', $colors) && $colors['primary'] === Color::Amber;
        }))->andReturnSelf();

        $panel->shouldReceive('discoverResources')->once()->with(
            m::on(function ($in) {
                return is_string($in) && str_ends_with($in, 'app/Filament/Resources');
            }),
            'App\Filament\Resources'
        )->andReturnSelf();

        $panel->shouldReceive('discoverPages')->once()->with(
            m::on(function ($in) {
                return is_string($in) && str_ends_with($in, 'app/Filament/Pages');
            }),
            'App\Filament\Pages'
        )->andReturnSelf();

        $panel->shouldReceive('pages')->once()->with(m::on(function (array $pages) {
            return in_array(Dashboard::class, $pages, true);
        }))->andReturnSelf();

        $panel->shouldReceive('discoverWidgets')->once()->with(
            m::on(function ($in) {
                return is_string($in) && str_ends_with($in, 'app/Filament/Widgets');
            }),
            'App\Filament\Widgets'
        )->andReturnSelf();

        $panel->shouldReceive('widgets')->once()->with(m::on(function (array $widgets) {
            return in_array(AccountWidget::class, $widgets, true)
                && in_array(FilamentInfoWidget::class, $widgets, true);
        }))->andReturnSelf();

        $panel->shouldReceive('middleware')->once()->with(m::on(function (array $middlewares) {
            $expected = [
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ];

            return $middlewares === $expected;
        }))->andReturnSelf();

        $panel->shouldReceive('authMiddleware')->once()->with([Authenticate::class])->andReturnSelf();

        $provider = new FreshguardPanelProvider();
        $result = $provider->panel($panel);

        $this->assertSame($panel, $result);
    }

    public function test_broken_chain_results_in_return_type_error(): void
    {
        // If the fluent chain returns a non-Panel instance, the provider's declared return type (Panel)
        // should cause a TypeError at return time.
        $panel = m::mock(Panel::class);

        // A dummy object that swallows any method calls and returns itself,
        // simulating a chain that no longer returns a Panel.
        $other = new class {
            public function __call(string $_name, array $_arguments)
            {
                unset($_name, $_arguments);
                return $this;
            }
        };

        $panel->shouldReceive('default')->once()->andReturnSelf();
        $panel->shouldReceive('id')->once()->with('freshguard')->andReturnSelf();
        $panel->shouldReceive('path')->once()->with('freshguard')->andReturnSelf();
        $panel->shouldReceive('login')->once()->andReturnSelf();
        $panel->shouldReceive('colors')->once()->with(m::type('array'))->andReturnSelf();
        $panel->shouldReceive('discoverResources')->once()->andReturnSelf();
        $panel->shouldReceive('discoverPages')->once()->andReturnSelf();
        $panel->shouldReceive('pages')->once()->andReturnSelf();
        $panel->shouldReceive('discoverWidgets')->once()->andReturnSelf();
        $panel->shouldReceive('widgets')->once()->andReturnSelf();

        // Return a non-Panel object mid-chain.
        $panel->shouldReceive('middleware')->once()->andReturn($other);

        $provider = new FreshguardPanelProvider();

        $this->expectException(TypeError::class);
        $provider->panel($panel);
    }

    public function test_colors_array_contains_only_expected_primary_key(): void
    {
        $panel = m::mock(Panel::class);

        $panel->shouldReceive('default')->andReturnSelf();
        $panel->shouldReceive('id')->andReturnSelf();
        $panel->shouldReceive('path')->andReturnSelf();
        $panel->shouldReceive('login')->andReturnSelf();

        $panel->shouldReceive('colors')
            ->once()
            ->with(m::on(function (array $colors) {
                return array_keys($colors) === ['primary'] && $colors['primary'] === Color::Amber;
            }))
            ->andReturnSelf();

        $panel->shouldReceive('discoverResources')->andReturnSelf();
        $panel->shouldReceive('discoverPages')->andReturnSelf();
        $panel->shouldReceive('pages')->andReturnSelf();
        $panel->shouldReceive('discoverWidgets')->andReturnSelf();
        $panel->shouldReceive('widgets')->andReturnSelf();
        $panel->shouldReceive('middleware')->andReturnSelf();
        $panel->shouldReceive('authMiddleware')->andReturnSelf();

        $provider = new FreshguardPanelProvider();
        $result = $provider->panel($panel);

        $this->assertSame($panel, $result);
    }
}