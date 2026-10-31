<?php

namespace DwivediAnuj\A11y;

use DwivediAnuj\A11y\Auth\ScanLoginController;
use DwivediAnuj\A11y\Commands\InstallCommand;
use DwivediAnuj\A11y\Commands\LintCommand;
use DwivediAnuj\A11y\Commands\ScanCommand;
use DwivediAnuj\A11y\Markers\BladeMarkers;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

class A11yServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/a11y.php', 'a11y');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/a11y.php' => config_path('a11y.php')], 'a11y-config');
            $this->commands([ScanCommand::class, LintCommand::class, InstallCommand::class]);
        }

        if (! static::enabled()) {
            return; // nothing below ever runs in production
        }

        if (config('a11y.markers')) {
            $this->callAfterResolving('blade.compiler', function (BladeCompiler $compiler) {
                BladeMarkers::register(
                    $compiler,
                    base_path(),
                    (bool) config('a11y.mark_vendor_views', true),
                    (bool) config('a11y.mark_component_call_sites', true),
                );
            });
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')
                ->get('_a11y/login/{user}', ScanLoginController::class)
                ->name('a11y.login');
        }
    }

    public static function enabled(): bool
    {
        return app()->environment((array) config('a11y.environments', ['local', 'testing']));
    }
}
