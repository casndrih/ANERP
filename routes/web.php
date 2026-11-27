<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\HumanResourcesController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;
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
    Route::get('/inventory/warehouses', [WarehouseController::class, 'index'])->name('inventory.warehouses.index');
    Route::post('/inventory/warehouses', [WarehouseController::class, 'store'])->name('inventory.warehouses.store');
    Route::get('/inventory/movements', [StockMovementController::class, 'index'])->name('inventory.movements.index');
    Route::post('/inventory/movements', [StockMovementController::class, 'store'])->name('inventory.movements.store');
    Route::post('/inventory/transfers', [StockMovementController::class, 'transfer'])->name('inventory.movements.transfer');
    Route::get('/procurement/suppliers', [SupplierController::class, 'index'])->name('procurement.suppliers.index');
    Route::post('/procurement/suppliers', [SupplierController::class, 'store'])->name('procurement.suppliers.store');
    Route::get('/procurement/orders', [PurchaseOrderController::class, 'index'])->name('procurement.orders.index');
    Route::post('/procurement/orders', [PurchaseOrderController::class, 'store'])->name('procurement.orders.store');
    Route::post('/procurement/orders/{order}/submit', [PurchaseOrderController::class, 'submit'])->name('procurement.orders.submit');
    Route::post('/procurement/orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('procurement.orders.approve');
    Route::post('/procurement/orders/{order}/lines/{line}/receive', [PurchaseOrderController::class, 'receive'])->name('procurement.orders.receive');

    Route::get('/hr', [HumanResourcesController::class, 'index'])->name('hr.index');
    Route::post('/hr/employees', [HumanResourcesController::class, 'storeEmployee'])->name('hr.employees.store');
    Route::post('/hr/leave', [HumanResourcesController::class, 'storeLeave'])->name('hr.leave.store');
    Route::post('/hr/leave/{leave}/decision', [HumanResourcesController::class, 'decideLeave'])->name('hr.leave.decision');
    Route::post('/hr/attendance', [HumanResourcesController::class, 'storeAttendance'])->name('hr.attendance.store');
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
    Route::post('/payroll/{run}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
    Route::get('/payroll/payslips/{line}', [PayrollController::class, 'payslip'])->name('payroll.payslip');
});