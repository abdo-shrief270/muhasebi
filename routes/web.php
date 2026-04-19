<?php

use App\Http\Controllers\Admin\LocaleToggleController;
use App\Http\Controllers\AdminHealthController;
use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/company/{tenant:slug}', LandingPageController::class)->name('landing.show');

// SuperAdmin operational health probe. Guarded by auth + super_admin so only
// platform operators can hit it; returns 503 when DB or cache are unreachable.
Route::middleware(['web', 'auth', 'super_admin'])
    ->get('/admin/health', [AdminHealthController::class, 'show'])
    ->name('admin.health');

Route::middleware(['web', 'auth', 'super_admin'])
    ->post('/admin/locale/toggle', LocaleToggleController::class)
    ->name('admin.locale.toggle');
