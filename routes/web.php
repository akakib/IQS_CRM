<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BreakController;
use App\Http\Controllers\DeskController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSettingsController;
use App\Http\Controllers\PackagingController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HotlineController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Webhooks\SteadfastWebhookController;
use App\Http\Controllers\Webhooks\WooCommerceWebhookController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\UsdLotController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\PointsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TrackingSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationRuleController;
use Illuminate\Support\Facades\Route;

// Called by other systems (no login; each verifies its own signature).
Route::post('/webhooks/woocommerce', WooCommerceWebhookController::class)->middleware('throttle:120,1')->name('webhooks.woocommerce');
Route::post('/webhooks/steadfast', SteadfastWebhookController::class)->middleware('throttle:300,1')->name('webhooks.steadfast');
Route::post('/webhooks/telegram', [TelegramController::class, 'webhook'])->middleware('throttle:300,1')->name('webhooks.telegram');

// Telegram Mini App (signs in with Telegram's signed initData, not a session).
Route::get('/tg/app', [TelegramController::class, 'app'])->name('tg.app');
Route::post('/tg/app/data', [TelegramController::class, 'appData'])->middleware('throttle:60,1')->name('tg.app.data');
Route::post('/tg/app/report', [TelegramController::class, 'appReport'])->middleware('throttle:60,1')->name('tg.app.report');

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
    Route::get('/dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
    Route::get('/kpi', [ReportController::class, 'kpi'])->name('kpi.index'); // own numbers for everyone; kpi.view sees the team
    Route::get('/analysis', [ReportController::class, 'analysis'])->middleware('can:analysis.view')->name('analysis.index');
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
    // Order management desk (moderators). New orders are only ever given by Take next.
    Route::get('/desk', [DeskController::class, 'index'])->middleware('can:orders.edit')->name('desk.index');
    Route::post('/desk/next', [DeskController::class, 'takeNext'])->middleware('can:orders.take')->name('desk.next');
    Route::get('/desk/pulse', [DeskController::class, 'pulse'])->middleware('can:orders.take')->name('desk.pulse');
    Route::post('/desk/{order}/act', [DeskController::class, 'act'])->middleware('can:orders.edit')->name('desk.act');
    Route::post('/desk/{order}/extend', [DeskController::class, 'extend'])->middleware('can:orders.edit')->name('desk.extend');

    Route::get('/desk/control', [TeamController::class, 'control'])->middleware('can:orders.reassign')->name('desk.control');
    Route::get('/desk/control/list', [TeamController::class, 'controlList'])->middleware('can:orders.reassign')->name('desk.control.list');
    Route::get('/orders/activity', [\App\Http\Controllers\OrderActivityController::class, 'index'])->middleware('can:orders.reassign')->name('orders.activity');
    Route::get('/attendance', [TeamController::class, 'attendance'])->middleware('can:attendance.view')->name('attendance.index');
    Route::middleware('can:attendance.edit')->group(function () {
        Route::post('/attendance/breaks/{break}', [TeamController::class, 'correctBreak'])->whereNumber('break')->name('attendance.breaks.correct');
        Route::post('/attendance/days/{day}/extra', [TeamController::class, 'approveExtra'])->whereNumber('day')->name('attendance.extra');
    });
    Route::post('/users/{user}/schedule', [TeamController::class, 'schedule'])->middleware('can:staff.edit')->name('users.schedule');
    Route::post('/kpi/targets', [TeamController::class, 'targets'])->middleware('can:settings.edit')->name('kpi.targets');

    // Breaks: everyone.
    Route::get('/breaks/reasons', [BreakController::class, 'reasons'])->name('breaks.reasons');
    Route::post('/breaks', [BreakController::class, 'start'])->name('breaks.start');
    Route::post('/breaks/end', [BreakController::class, 'end'])->name('breaks.end');

    Route::get('/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');
    Route::post('/orders/{order}/transition', [OrderController::class, 'transition'])->middleware('can:orders.view')->name('orders.transition');
    Route::post('/orders/{order}/notes', [OrderController::class, 'note'])->middleware('can:orders.view')->name('orders.notes');
    Route::post('/orders/{order}/presence', [OrderController::class, 'presence'])->middleware('can:orders.view')->name('orders.presence');
    Route::post('/orders/{order}/cod-updated', [OrderController::class, 'codUpdated'])->middleware('can:orders.edit')->name('orders.cod-updated');
    Route::post('/orders/{order}/courier-cancelled', [OrderController::class, 'courierCancelled'])->middleware('can:orders.edit')->name('orders.courier-cancelled');
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

    // Packaging and handover
    // The module used to live at /packing: old bookmarks and notification links still land.
    Route::get('/packing/{rest?}', fn (?string $rest = null) => redirect('/packaging'.($rest ? '/'.$rest : '')))->where('rest', '.*');
    Route::middleware('can:packaging.view')->group(function () {
        Route::get('/packaging', [PackagingController::class, 'index'])->name('packaging.index');
        // GET only: Route::redirect answers every verb and, once routes are cached, swallowed the scan POST below.
        Route::get('/packaging/scan', fn () => redirect('/packaging'))->name('packaging.scan');
        Route::get('/packaging/labels', [PackagingController::class, 'labels'])->name('packaging.labels');
        Route::post('/packaging/{order}/label', [PackagingController::class, 'label'])->name('packaging.label');
        Route::get('/packaging/orders/{order}', [PackagingController::class, 'preview'])->name('packaging.preview');
        Route::get('/packaging/batches/{batch}', [PackagingController::class, 'batch'])->whereNumber('batch')->name('packaging.batch');
        Route::post('/packaging/report', [PackagingController::class, 'report'])->name('packaging.report');
        Route::get('/packaging/issues', [PackagingController::class, 'issues'])->name('packaging.issues');
        Route::post('/packaging/issues/{report}', [PackagingController::class, 'resolve'])->whereNumber('report')->name('packaging.issues.resolve');
        Route::get('/handover', [HandoverController::class, 'index'])->name('handover.index');
        Route::get('/handover/{session}', [HandoverController::class, 'show'])->whereNumber('session')->name('handover.show');
        Route::get('/handover/{session}/manifest', [HandoverController::class, 'manifest'])->whereNumber('session')->name('handover.manifest');
    });
    Route::post('/packaging/shift', [PackagingController::class, 'shift'])->middleware('can:packaging.manage')->name('packaging.shift');
    Route::middleware('can:packaging.create')->group(function () {
        Route::post('/packaging/scan', [PackagingController::class, 'scan'])->name('packaging.scan.post');
        Route::post('/packaging/orders/{order}/pack', [PackagingController::class, 'pack'])->name('packaging.pack');
        Route::post('/packaging/orders/{order}/hold', [PackagingController::class, 'hold'])->name('packaging.hold');
        Route::post('/packaging/release', [PackagingController::class, 'release'])->name('packaging.release');
        Route::post('/packaging/batches/{batch}/picked', [PackagingController::class, 'picked'])->whereNumber('batch')->name('packaging.picked');
        Route::post('/packaging/batches/{batch}/done', [PackagingController::class, 'done'])->whereNumber('batch')->name('packaging.done');
        Route::post('/handover', [HandoverController::class, 'start'])->name('handover.start');
        Route::post('/handover/{session}/scan', [HandoverController::class, 'scan'])->whereNumber('session')->name('handover.scan');
        Route::post('/handover/{session}/manual', [HandoverController::class, 'manual'])->whereNumber('session')->name('handover.manual');
        Route::post('/handover/{session}/close', [HandoverController::class, 'close'])->whereNumber('session')->name('handover.close');
    });

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
        Route::post('/settings/charges/mode', [OrderSettingsController::class, 'chargeMode'])->name('settings.charges.mode');
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

    // Marketing: ad spend, ROAS and dollar lots.
    Route::get('/marketing', [MarketingController::class, 'index'])->middleware('can:marketing.view')->name('marketing.index');
    Route::get('/usd-lots', [UsdLotController::class, 'index'])->middleware('can:marketing.view')->name('usd-lots.index');
    Route::middleware('can:marketing.create')->group(function () {
        Route::post('/marketing/accounts', [MarketingController::class, 'storeAccount'])->name('marketing.accounts.store');
        Route::post('/marketing/accounts/{account}/toggle', [MarketingController::class, 'toggleAccount'])->whereNumber('account')->name('marketing.accounts.toggle');
        Route::post('/marketing/spend', [MarketingController::class, 'storeSpend'])->name('marketing.spend.store');
        Route::post('/marketing/import', [MarketingController::class, 'import'])->name('marketing.import');
        Route::post('/marketing/pull', [MarketingController::class, 'pull'])->name('marketing.pull');
        Route::post('/usd-lots', [UsdLotController::class, 'storeLot'])->name('usd-lots.store');
        Route::post('/usd-lots/vendors', [UsdLotController::class, 'storeVendor'])->name('usd-lots.vendors.store');
        Route::post('/usd-lots/payments', [UsdLotController::class, 'storePayment'])->name('usd-lots.payments.store');
    });

    // Points: everyone sees their own; managing rules and reviews needs points.manage.
    Route::get('/points', [PointsController::class, 'mine'])->name('points.mine');
    Route::post('/points/{entry}/dispute', [PointsController::class, 'dispute'])->whereNumber('entry')->name('points.dispute');
    Route::middleware('can:points.manage')->group(function () {
        Route::get('/points/review', [PointsController::class, 'review'])->name('points.review');
        Route::post('/points/review/flags/{flag}', [PointsController::class, 'decideFlag'])->whereNumber('flag')->name('points.review.flag');
        Route::post('/points/review/disputes/{entry}', [PointsController::class, 'decideDispute'])->whereNumber('entry')->name('points.review.dispute');
        Route::post('/points/review/qa/{order}', [PointsController::class, 'storeQa'])->name('points.review.qa');
        Route::get('/settings/points', [PointsController::class, 'rules'])->name('settings.points');
        Route::post('/settings/points', [PointsController::class, 'storeRule'])->name('settings.points.store');
        Route::put('/settings/points/{rule}', [PointsController::class, 'updateRule'])->whereNumber('rule')->name('settings.points.update');
        Route::post('/settings/points/test', [PointsController::class, 'test'])->name('settings.points.test');
    });

    Route::get('/activity', [ActivityLogController::class, 'index'])->middleware('can:activity.view')->name('activity.index');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::middleware('can:access.manage')->group(function () {
        Route::get('/health', HealthController::class)->name('health');
        Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('settings.integrations');
        Route::get('/settings/couriers', [\App\Http\Controllers\CourierAccountController::class, 'index'])->name('settings.couriers');
        Route::post('/settings/couriers', [\App\Http\Controllers\CourierAccountController::class, 'store'])->name('settings.couriers.store');
        Route::put('/settings/couriers/{account}', [\App\Http\Controllers\CourierAccountController::class, 'update'])->whereNumber('account')->name('settings.couriers.update');
        Route::post('/settings/couriers/{account}/check', [\App\Http\Controllers\CourierAccountController::class, 'check'])->whereNumber('account')->name('settings.couriers.check');
        Route::post('/settings/couriers/{account}/test-webhook', [\App\Http\Controllers\CourierAccountController::class, 'testWebhook'])->whereNumber('account')->name('settings.couriers.test-webhook');
        Route::post('/settings/integrations/inbox/{inbox}/retry', [IntegrationController::class, 'retry'])->whereNumber('inbox')->name('settings.integrations.retry');

        Route::resource('roles', RoleController::class)->except(['index', 'show']);

        Route::get('/users/{user}/access', [UserAccessController::class, 'edit'])->name('users.access');
        Route::post('/users/{user}/access/roles', [UserAccessController::class, 'storeRole'])->name('users.access.roles.store');
        Route::delete('/users/{user}/access/roles/{assignment}', [UserAccessController::class, 'destroyRole'])->name('users.access.roles.destroy');
        Route::post('/users/{user}/access/overrides', [UserAccessController::class, 'storeOverride'])->name('users.access.overrides.store');
        Route::delete('/users/{user}/access/overrides/{override}', [UserAccessController::class, 'destroyOverride'])->name('users.access.overrides.destroy');
    });
});
