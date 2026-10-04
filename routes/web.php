<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CallQueueController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSettingsController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HotlineController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Webhooks\SteadfastWebhookController;
use App\Http\Controllers\Webhooks\WooCommerceWebhookController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\TrackingSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationRuleController;
use Illuminate\Support\Facades\Route;

// Called by other systems (no login; each verifies its own signature).
Route::post('/webhooks/woocommerce', WooCommerceWebhookController::class)->middleware('throttle:120,1')->name('webhooks.woocommerce');
Route::post('/webhooks/steadfast', SteadfastWebhookController::class)->middleware('throttle:300,1')->name('webhooks.steadfast');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::post('/locations/bulk', [LocationController::class, 'bulk'])->middleware('can:locations.edit')->name('locations.bulk');
    Route::resource('locations', LocationController::class)->except('show')
        ->middlewareFor('index', 'can:locations.view')
        ->middlewareFor(['create', 'store'], 'can:locations.create')
        ->middlewareFor(['edit', 'update'], 'can:locations.edit')
        ->middlewareFor('destroy', 'can:locations.delete');

    // Catalog. Static paths before the resource so they are not read as {product}.
    Route::get('/products/search', [ProductController::class, 'search'])->middleware('can:products.view')->name('products.search');
    Route::get('/products/availability', [AvailabilityController::class, 'index'])->middleware('can:products.availability')->name('products.availability');
    Route::post('/products/availability', [AvailabilityController::class, 'update'])->middleware('can:products.availability')->name('products.availability.update');
    Route::middleware('can:products.create')->group(function () {
        Route::get('/products/import', [ProductImportController::class, 'index'])->name('products.import');
        Route::post('/products/import', [ProductImportController::class, 'store'])->name('products.import.store');
        Route::get('/products/import/{import}', [ProductImportController::class, 'show'])->whereNumber('import')->name('products.import.show');
        Route::post('/products/import/{import}/step', [ProductImportController::class, 'step'])->whereNumber('import')->name('products.import.step');
    });
    Route::resource('products', ProductController::class)->except('show')
        ->middlewareFor('index', 'can:products.view')
        ->middlewareFor(['create', 'store'], 'can:products.create')
        ->middlewareFor(['edit', 'update'], 'can:products.edit')
        ->middlewareFor('destroy', 'can:products.delete');
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middleware('can:products.edit');

    // Orders
    Route::get('/orders/delivery-charge', [OrderController::class, 'deliveryCharge'])->middleware('can:orders.create')->name('orders.delivery-charge');
    Route::get('/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index');
    Route::get('/orders/queue', [CallQueueController::class, 'index'])->middleware('can:orders.edit')->name('orders.queue');
    Route::post('/orders/queue/next', [CallQueueController::class, 'takeNext'])->middleware('can:orders.edit')->name('orders.queue.next');
    Route::post('/orders/{order}/call', [CallQueueController::class, 'logCall'])->middleware('can:orders.edit')->name('orders.queue.call');
    Route::get('/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');
    Route::post('/orders/{order}/claim', [OrderController::class, 'claim'])->middleware('can:orders.edit')->name('orders.claim');
    Route::post('/orders/{order}/transition', [OrderController::class, 'transition'])->middleware('can:orders.view')->name('orders.transition');
    Route::post('/orders/{order}/notes', [OrderController::class, 'note'])->middleware('can:orders.view')->name('orders.notes');
    Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->middleware('can:orders.edit')->name('orders.edit');
    Route::post('/orders/{order}/amend', [OrderController::class, 'amend'])->middleware('can:orders.edit')->name('orders.amend');
    Route::post('/orders/{order}/amendments/{amendment}', [OrderController::class, 'decideAmendment'])->whereNumber('amendment')->middleware('can:orders.approve')->name('orders.amendments.decide');
    Route::post('/orders/{order}/reassign', [OrderController::class, 'reassign'])->middleware('can:orders.reassign')->name('orders.reassign');
    Route::post('/orders/{order}/payments/{payment}', [OrderController::class, 'verifyPayment'])->whereNumber('payment')->middleware('can:orders.approve')->name('orders.payments.verify');

    // Courier desk
    Route::get('/shipping', [ShippingController::class, 'index'])->middleware('can:shipping.view')->name('shipping.index');
    Route::post('/shipping/book', [ShippingController::class, 'book'])->middleware('can:shipping.create')->name('shipping.book');
    Route::get('/shipping/labels', [ShippingController::class, 'labels'])->middleware('can:shipping.view')->name('shipping.labels');
    Route::post('/shipping/resync', [ShippingController::class, 'resync'])->middleware('can:shipping.create')->name('shipping.resync');
    Route::post('/shipping/{order}/reprint', [ShippingController::class, 'reprint'])->middleware('can:shipping.create')->name('shipping.reprint');

    // Rider hotline and delivery issues
    Route::get('/hotline', [HotlineController::class, 'index'])->middleware('can:hotline.view')->name('hotline.index');
    Route::post('/hotline/{order}/solved', [HotlineController::class, 'solved'])->middleware('can:hotline.view')->name('hotline.solved');
    Route::post('/hotline/{order}/issue', [HotlineController::class, 'openIssue'])->middleware('can:hotline.create')->name('hotline.issue');
    Route::get('/issues', [HotlineController::class, 'issues'])->middleware('can:orders.view')->name('issues.index');
    Route::post('/issues/{issue}/resolve', [HotlineController::class, 'resolve'])->whereNumber('issue')->middleware('can:orders.view')->name('issues.resolve');

    // Customers
    Route::get('/customers/lookup', [CustomerController::class, 'lookup'])->middleware('can:customers.view')->name('customers.lookup');
    Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('can:customers.edit')->name('customers.merge');
    Route::post('/customers/{customer}/fraud-check', [CustomerController::class, 'fraudCheck'])->middleware('can:customers.view')->name('customers.fraud-check');
    Route::resource('customers', CustomerController::class)
        ->middlewareFor(['index', 'show'], 'can:customers.view')
        ->middlewareFor(['create', 'store'], 'can:customers.create')
        ->middlewareFor(['edit', 'update'], 'can:customers.edit')
        ->middlewareFor('destroy', 'can:customers.delete');

    Route::resource('users', UserController::class)->except(['show', 'destroy'])
        ->middlewareFor('index', 'can:staff.view')
        ->middlewareFor(['create', 'store'], 'can:staff.create')
        ->middlewareFor(['edit', 'update'], 'can:staff.edit');
    // Deactivate/activate is the "delete" action for staff (they are never deleted).
    Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])
        ->middleware('can:staff.delete')->name('users.status');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->whereNumber('id')->name('notifications.open');

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('can:settings.view')->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');

    Route::get('/settings/verification', [VerificationRuleController::class, 'index'])->middleware('can:settings.view')->name('settings.verification');
    Route::post('/settings/verification/test', [VerificationRuleController::class, 'test'])->middleware('can:settings.view')->name('settings.verification.test');
    Route::middleware('can:settings.edit')->group(function () {
        Route::post('/settings/verification', [VerificationRuleController::class, 'store'])->name('settings.verification.store');
        Route::post('/settings/verification/{rule}/toggle', [VerificationRuleController::class, 'toggle'])->whereNumber('rule')->name('settings.verification.toggle');
        Route::delete('/settings/verification/{rule}', [VerificationRuleController::class, 'destroy'])->whereNumber('rule')->name('settings.verification.destroy');
    });
    Route::post('/orders/{order}/verify', [VerificationRuleController::class, 'rerun'])->middleware('can:orders.approve')->name('orders.verify');

    Route::get('/settings/tracking', [TrackingSettingsController::class, 'index'])->middleware('can:settings.view')->name('settings.tracking');
    Route::put('/settings/tracking/{event}', [TrackingSettingsController::class, 'update'])->whereNumber('event')->middleware('can:settings.edit')->name('settings.tracking.update');

    Route::get('/settings/charges', [OrderSettingsController::class, 'charges'])->middleware('can:settings.view')->name('settings.charges');
    Route::get('/settings/reasons', [OrderSettingsController::class, 'reasons'])->middleware('can:settings.view')->name('settings.reasons');
    Route::middleware('can:settings.edit')->group(function () {
        Route::post('/settings/charges/zones', [OrderSettingsController::class, 'storeZone'])->name('settings.charges.zones.store');
        Route::post('/settings/charges/rules', [OrderSettingsController::class, 'storeRule'])->name('settings.charges.rules.store');
        Route::post('/settings/charges/rules/{rule}/toggle', [OrderSettingsController::class, 'toggleRule'])->whereNumber('rule')->name('settings.charges.rules.toggle');
        Route::post('/settings/reasons', [OrderSettingsController::class, 'storeReason'])->name('settings.reasons.store');
        Route::post('/settings/reasons/{reason}/toggle', [OrderSettingsController::class, 'toggleReason'])->whereNumber('reason')->name('settings.reasons.toggle');
        Route::put('/settings/statuses/{status}', [OrderSettingsController::class, 'updateStatus'])->whereNumber('status')->name('settings.reasons.status');
    });

    Route::get('/settings/notifications', [NotificationRuleController::class, 'index'])->middleware('can:settings.view')->name('settings.notifications');
    Route::middleware('can:settings.edit')->group(function () {
        Route::put('/settings/notifications/types/{type}', [NotificationRuleController::class, 'updateType'])->whereNumber('type')->name('settings.notifications.types.update');
        Route::post('/settings/notifications/rules', [NotificationRuleController::class, 'store'])->name('settings.notifications.rules.store');
        Route::delete('/settings/notifications/rules/{rule}', [NotificationRuleController::class, 'destroy'])->whereNumber('rule')->name('settings.notifications.rules.destroy');
        Route::post('/settings/notifications/test', [NotificationRuleController::class, 'test'])->name('settings.notifications.test');
    });

    // Component library: local development only.
    if (app()->environment('local', 'testing')) {
        Route::view('/dev/components', 'dev.components')->name('dev.components');
        Route::get('/dev/search-demo', fn (\Illuminate\Http\Request $r) => \App\Models\User::query()
            ->where('name', 'like', $r->query('q', '').'%')->orderBy('name')->limit(20)
            ->get(['id', 'name', 'email'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'sub' => $u->email]))
            ->name('dev.search-demo');
    }

    Route::get('/activity', [ActivityLogController::class, 'index'])->middleware('can:activity.view')->name('activity.index');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::middleware('can:access.manage')->group(function () {
        Route::get('/health', HealthController::class)->name('health');
        Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('settings.integrations');
        Route::post('/settings/integrations/inbox/{inbox}/retry', [IntegrationController::class, 'retry'])->whereNumber('inbox')->name('settings.integrations.retry');

        Route::resource('roles', RoleController::class)->except(['index', 'show']);

        Route::get('/users/{user}/access', [UserAccessController::class, 'edit'])->name('users.access');
        Route::post('/users/{user}/access/roles', [UserAccessController::class, 'storeRole'])->name('users.access.roles.store');
        Route::delete('/users/{user}/access/roles/{assignment}', [UserAccessController::class, 'destroyRole'])->name('users.access.roles.destroy');
        Route::post('/users/{user}/access/overrides', [UserAccessController::class, 'storeOverride'])->name('users.access.overrides.store');
        Route::delete('/users/{user}/access/overrides/{override}', [UserAccessController::class, 'destroyOverride'])->name('users.access.overrides.destroy');
    });
});
