<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Catalog\Store\FakeStoreDriver;
use App\Services\Catalog\Store\StoreDriver;
use App\Services\Catalog\Store\WooCommerceDriver;
use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierManager;
use App\Services\PermissionService;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
        $this->app->scoped(\App\Services\Work\WorkCalendar::class);
        $this->app->scoped(\App\Services\Work\DeskRules::class);

        // Website receiver: fake everywhere except production with STORE_DRIVER=woocommerce.
        $this->app->singleton(StoreDriver::class, fn ($app) => $app->environment(['testing', 'staging', 'local']) || config('store.driver') !== 'woocommerce'
            ? new FakeStoreDriver
            : new WooCommerceDriver(array_merge(config('store.woocommerce'), array_filter([
                'url' => \App\Models\WebsiteAccount::current()?->url,
                'key' => \App\Models\WebsiteAccount::current()?->consumer_key,
                'secret' => \App\Models\WebsiteAccount::current()?->consumer_secret,
            ]))));
        $this->app->bind(CourierDriver::class, fn ($app) => $app->make(CourierManager::class)->driver());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // N+1 queries fail loudly everywhere except production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Slow-query log where real data lives (local uses the query budget tests instead).
        if (! $this->app->environment(['local', 'testing'])) {
            DB::listen(function (QueryExecuted $query) {
                if ($query->time > config('database.slow_query_ms', 200)) {
                    Log::warning('Slow query', [
                        'ms' => $query->time,
                        'sql' => $query->sql,
                        'url' => app()->runningInConsole() ? 'console' : request()->path(),
                    ]);
                }
            });
        }

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

        // Side effects of status changes (each module subscribes here).
        \App\Services\Orders\OrderStateMachine::resetListeners();
        \App\Services\Orders\OrderStateMachine::listen(fn ($order, $from, $to) => app(\App\Services\Orders\ConfirmationEffects::class)->handle($order, $from, $to));
        \App\Services\Orders\OrderStateMachine::listen(function ($order, $from, $to) {
            if (in_array($to['key'], ['record_verified', 'confirmed', 'delivered'], true)) {
                app(\App\Services\Tracking\TrackingService::class)->handle($order, $to['key']);
            }
        });

        \App\Services\Orders\OrderStateMachine::listen(fn ($order, $from, $to, $user = null) => app(\App\Services\Points\PointHooks::class)->transition($order, $from, $to, $user));

        \App\Services\Orders\OrderStateMachine::listen(fn ($order, $from, $to, $user = null) => app(\App\Services\Orders\DeskService::class)->onTransition($order, $from, $to, $user));

        // Rider calls: the hotline permission or the Rider line channel.
        Gate::define('rider-calls', fn (User $user) => $user->handlesRiders());

        // Roles and access assignments: Owner only.
        Gate::define('access.manage', fn (User $user) => $user->isOwner());

        // @canseefield('cost_price') ... @endcanseefield
        Blade::if('canseefield', fn (string $field) => (bool) auth()->user()?->canSeeField($field));
    }
}
