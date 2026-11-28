<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCreditPolicy
{
    /**
     * Validate the customer's total exposure before a new credit commitment.
     * Exposure includes unpaid posted invoices and approved, unfulfilled sales orders.
     * Posted receipts and posted customer credit notes reduce the invoice balance.
     */
    public function assertCanExtendCredit(
        int $companyId,
        int $customerId,
        float $proposedAmount,
        ?int $excludeSalesOrderId = null
    ): void {
        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($customerId);

        if ($customer->credit_hold) {
            throw ValidationException::withMessages([
                'customer_id' => $customer->credit_hold_reason
                    ? 'Customer is on credit hold: '.$customer->credit_hold_reason
                    : 'Customer is on credit hold. Contact an authorised finance administrator.',
            ]);
        }

        if ($customer->credit_limit === null) {
            return;
        }

        $invoiced = (float) DB::table('customer_invoices')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
            ->sum('amount');

        $receipts = (float) DB::table('customer_receipt_allocations')
            ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
            ->join('customer_invoices', 'customer_invoices.id', '=', 'customer_receipt_allocations.customer_invoice_id')
            ->where('customer_receipts.company_id', $companyId)
            ->where('customer_invoices.customer_id', $customerId)
            ->where('customer_receipts.status', 'posted')
            ->sum('customer_receipt_allocations.amount');

        $credits = (float) DB::table('customer_credit_notes')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
            ->sum('amount');

        $committedOrders = (float) DB::table('sales_orders')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'approved')
            ->when($excludeSalesOrderId, fn ($query) => $query->where('id', '!=', $excludeSalesOrderId))
            ->sum('total_amount');

        $outstandingInvoices = max(0, round($invoiced - $receipts - $credits, 2));
        $exposure = round($outstandingInvoices + $committedOrders + max(0, $proposedAmount), 2);
        $limit = round((float) $customer->credit_limit, 2);

        if ($exposure > $limit + 0.009) {
            $available = max(0, round($limit - $outstandingInvoices - $committedOrders, 2));
            throw ValidationException::withMessages([
                'customer_id' => 'Customer credit limit exceeded. Available credit: PGK '.number_format($available, 2).'; proposed commitment: PGK '.number_format($proposedAmount, 2).'.',
            ]);
        }
    }
}
