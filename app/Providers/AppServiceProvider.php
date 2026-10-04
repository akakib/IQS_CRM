<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierManager;
use App\Services\PermissionService;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionService::class);
        $this->app->singleton(CourierManager::class);
        $this->app->singleton(SettingsService::class);
        $this->app->bind(CourierDriver::class, fn ($app) => $app->make(CourierManager::class)->driver());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // N+1 queries fail loudly everywhere except production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Every 'module.action' from config/permissions.php is answered by
        // PermissionService, so @can, can: middleware and $user->can() agree.
        $known = [];
        foreach (config('permissions.modules', []) as $module => $actions) {
            foreach ($actions as $action) {
                $known["{$module}.{$action}"] = true;
            }
        }

        Gate::before(function (User $user, string $ability) use ($known) {
            if (! isset($known[$ability])) {
                return null;
            }

            return $user->hasPermission($ability);
        });

        Event::listen(Login::class, fn (Login $e) => app(ActivityLogger::class)->log('auth.login', $e->user));

        // Roles and access assignments: Owner only.
        Gate::define('access.manage', fn (User $user) => $user->isOwner());

        // @canseefield('cost_price') ... @endcanseefield
        Blade::if('canseefield', fn (string $field) => (bool) auth()->user()?->canSeeField($field));
    }
}
