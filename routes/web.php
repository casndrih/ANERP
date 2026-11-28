<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\HumanResourcesController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ReportingController;
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
    Route::post('/hr/super-participation', [HumanResourcesController::class, 'setVoluntarySuperParticipation'])->name('hr.super-participation.store');
    Route::post('/hr/leave-types', [HumanResourcesController::class, 'storeLeaveType'])->name('hr.leave-types.store');
    Route::post('/hr/leave', [HumanResourcesController::class, 'storeLeave'])->name('hr.leave.store');
    Route::post('/hr/leave/{leave}/decision', [HumanResourcesController::class, 'decideLeave'])->name('hr.leave.decision');
    Route::post('/hr/attendance', [HumanResourcesController::class, 'storeAttendance'])->name('hr.attendance.store');
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('/finance/customers', [FinanceController::class, 'storeCustomer'])->name('finance.customers.store');
    Route::post('/finance/journals', [FinanceController::class, 'storeJournal'])->name('finance.journals.store');
    Route::post('/finance/journals/{journal}/post', [FinanceController::class, 'postJournal'])->name('finance.journals.post');
    Route::post('/finance/bills', [FinanceController::class, 'storeSupplierBill'])->name('finance.bills.store');
    Route::post('/finance/bills/{bill}/post', [FinanceController::class, 'postSupplierBill'])->name('finance.bills.post');
    Route::post('/finance/invoices', [FinanceController::class, 'storeInvoice'])->name('finance.invoices.store');
    Route::post('/finance/invoices/{invoice}/post', [FinanceController::class, 'postInvoice'])->name('finance.invoices.post');
    Route::get('/finance/payments', [SupplierPaymentController::class, 'index'])->name('finance.payments.index');
    Route::post('/finance/payments', [SupplierPaymentController::class, 'store'])->name('finance.payments.store');
    Route::post('/finance/payments/{payment}/post', [SupplierPaymentController::class, 'post'])->name('finance.payments.post');
    Route::post('/finance/bank-accounts', [FinanceController::class, 'storeBankAccount'])->name('finance.bank-accounts.store');
    Route::post('/finance/bank-transactions', [FinanceController::class, 'storeBankTransaction'])->name('finance.bank-transactions.store');
    Route::post('/finance/bank-accounts/{bankAccount}/reconcile', [FinanceController::class, 'reconcile'])->name('finance.bank-accounts.reconcile');
    Route::get('/reports', [ReportingController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportingController::class, 'export'])->name('reports.export');
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
    Route::post('/payroll/{run}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
    Route::get('/payroll/payslips/{line}', [PayrollController::class, 'payslip'])->name('payroll.payslip');
});