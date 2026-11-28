<?php

namespace App\Providers;

use App\Http\Controllers\CustomerCreditController;
use App\Http\Middleware\AuditCompanyActivity;
use App\Http\Middleware\EnsureCompanyAccess;
use App\Http\Middleware\EnsureMfaVerified;
use App\Models\CustomerInvoice;
use App\Models\SalesOrder;
use App\Services\CustomerCreditPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
            return Limit::perMinute(5)->by($key);
        });

        SalesOrder::updating(function (SalesOrder $order): void {
            if ($order->isDirty('status') && $order->status === 'approved') {
                app(CustomerCreditPolicy::class)->assertCanExtendCredit(
                    (int) $order->company_id,
                    (int) $order->customer_id,
                    (float) $order->total_amount,
                    (int) $order->id
                );
            }
        });

        CustomerInvoice::updating(function (CustomerInvoice $invoice): void {
            if ($invoice->isDirty('status') && $invoice->status === 'posted') {
                app(CustomerCreditPolicy::class)->assertCanExtendCredit(
                    (int) $invoice->company_id,
                    (int) $invoice->customer_id,
                    (float) $invoice->amount
                );
            }
        });

        Route::middleware([
            'web', 'auth', EnsureCompanyAccess::class,
            EnsureMfaVerified::class, AuditCompanyActivity::class,
        ])->group(function (): void {
            Route::get('/finance/customer-credit', [CustomerCreditController::class, 'index'])
                ->name('finance.customer-credit.index');
            Route::patch('/finance/customer-credit/{customer}', [CustomerCreditController::class, 'update'])
                ->name('finance.customer-credit.update');
        });
    }
}
