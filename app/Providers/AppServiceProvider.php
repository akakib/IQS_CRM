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
        $this->app->scoped(\App\Services\Packaging\PackagingPause::class);

        // Website receiver: fake everywhere except production with STORE_DRIVER=woocommerce. A staging server connected
        // to a test website (STORE_ORDER_STATUS_LIVE=true) sends order statuses for real and keeps catalog pushes fake.
        $this->app->singleton(StoreDriver::class, function ($app) {
            $woo = function () {
                $account = \App\Models\WebsiteAccount::current(); // read only when the real website is used

                return new WooCommerceDriver(array_merge(config('store.woocommerce'), array_filter([
                    'url' => $account?->url, 'key' => $account?->consumer_key, 'secret' => $account?->consumer_secret,
                ])));
            };
            if ($app->environment('staging') && config('store.order_status_live')) {
                return new \App\Services\Catalog\Store\SplitStoreDriver(new FakeStoreDriver, $woo());
            }

            return $app->environment(['testing', 'staging', 'local']) || config('store.driver') !== 'woocommerce' ? new FakeStoreDriver : $woo();
        });
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

        // An order already in the packers' list put on hold (by the desk or a manager): the packers set the parcel aside.
        \App\Services\Orders\OrderStateMachine::listen(function ($order, $from, $to, $user = null) {
            // (Held by its own packer, who knows: no notice.)
            if ($from['key'] === 'ready_for_packaging' && $to['key'] === 'hold' && ! ($user && (int) $order->packer_id === (int) $user->id)) {
                $reason = $order->hold_reason_id ? \Illuminate\Support\Facades\DB::table('status_reasons')->where('id', $order->hold_reason_id)->value('label_en') : null;
                $label = \Illuminate\Support\Facades\DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')->value('barcode');
                app(\App\Services\NotificationService::class)->send('order_held_in_shop', __(':no on hold: set the parcel aside, do not send', ['no' => $order->order_no]),
                    trim(($label ? __('Label :l', ['l' => $label]).'. ' : '').($reason ? __($reason) : '')), [
                        'link' => route('packaging.index', ['show' => 'hold']), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                    ]);
            }
        });
        // A website order record-verified here is set to Record verified on the website too (after the response); one
        // confirmed without passing Record verified (held for an advance, then confirmed) is set then. Only once: after
        // it is set, applies() is false.
        \App\Services\Orders\OrderStateMachine::listen(function ($order, $from, $to) {
            if (in_array($to['key'], ['record_verified', 'confirmed'], true) && app(\App\Services\Orders\WebsiteStatusPush::class)->applies($order)) {
                $id = $order->id;
                app()->terminating(fn () => app(\App\Services\Orders\WebsiteStatusPush::class)->push(\App\Models\Order::find($id)));
            }
        });

        \App\Services\Orders\OrderStateMachine::listen(fn ($order, $from, $to, $user = null) => app(\App\Services\Points\PointHooks::class)->transition($order, $from, $to, $user));

        \App\Services\Orders\OrderStateMachine::listen(fn ($order, $from, $to, $user = null) => app(\App\Services\Orders\DeskService::class)->onTransition($order, $from, $to, $user));

        // Cancelled or returned with money paid: it is owed back (Payments, Refunds).
        \App\Services\Orders\OrderStateMachine::listen(function ($order, $from, $to) {
            if (in_array($to['key'], \App\Services\Orders\RefundService::CLOSED, true) && (float) $order->advance_verified > 0) {
                app(\App\Services\Orders\RefundService::class)->closed($order);
            }
        });

        // Rider calls: the hotline permission or the Rider line channel.
        Gate::define('rider-calls', fn (User $user) => $user->handlesRiders());

        // Roles and access assignments: Owner only.
        Gate::define('access.manage', fn (User $user) => $user->isOwner());

        // @canseefield('cost_price') ... @endcanseefield
        Blade::if('canseefield', fn (string $field) => (bool) auth()->user()?->canSeeField($field));
    }
}
