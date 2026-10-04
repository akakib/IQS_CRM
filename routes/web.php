<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
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

    Route::resource('locations', LocationController::class)->except('show')
        ->middlewareFor('index', 'can:locations.view')
        ->middlewareFor(['create', 'store'], 'can:locations.create')
        ->middlewareFor(['edit', 'update'], 'can:locations.edit')
        ->middlewareFor('destroy', 'can:locations.delete');

    Route::resource('users', UserController::class)->except(['show', 'destroy'])
        ->middlewareFor('index', 'can:staff.view')
        ->middlewareFor(['create', 'store'], 'can:staff.create')
        ->middlewareFor(['edit', 'update'], 'can:staff.edit');
    // Deactivate/activate is the "delete" action for staff (they are never deleted).
    Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])
        ->middleware('can:staff.delete')->name('users.status');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::middleware('can:access.manage')->group(function () {
        Route::resource('roles', RoleController::class)->except(['index', 'show']);

        Route::get('/users/{user}/access', [UserAccessController::class, 'edit'])->name('users.access');
        Route::post('/users/{user}/access/roles', [UserAccessController::class, 'storeRole'])->name('users.access.roles.store');
        Route::delete('/users/{user}/access/roles/{assignment}', [UserAccessController::class, 'destroyRole'])->name('users.access.roles.destroy');
        Route::post('/users/{user}/access/overrides', [UserAccessController::class, 'storeOverride'])->name('users.access.overrides.store');
        Route::delete('/users/{user}/access/overrides/{override}', [UserAccessController::class, 'destroyOverride'])->name('users.access.overrides.destroy');
    });
});
