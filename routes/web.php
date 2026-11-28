<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\EnsureCompanyAccess;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::post('/company/select', [CompanySelectionController::class, 'store'])->middleware('auth')->name('company.select');

Route::middleware(['auth', EnsureCompanyAccess::class])->group(function () {
    Route::get('/', fn () => view('dashboard'))->name('dashboard');
    Route::get('/inventory/products', [ProductController::class, 'index'])->name('inventory.products.index');
    Route::post('/inventory/products', [ProductController::class, 'store'])->name('inventory.products.store');
});
