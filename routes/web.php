<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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

        Route::resource('roles', RoleController::class)->except(['index', 'show']);

        Route::get('/users/{user}/access', [UserAccessController::class, 'edit'])->name('users.access');
        Route::post('/users/{user}/access/roles', [UserAccessController::class, 'storeRole'])->name('users.access.roles.store');
        Route::delete('/users/{user}/access/roles/{assignment}', [UserAccessController::class, 'destroyRole'])->name('users.access.roles.destroy');
        Route::post('/users/{user}/access/overrides', [UserAccessController::class, 'storeOverride'])->name('users.access.overrides.store');
        Route::delete('/users/{user}/access/overrides/{override}', [UserAccessController::class, 'destroyOverride'])->name('users.access.overrides.destroy');
    });
});
