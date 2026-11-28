<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SupplierBill;
use App\Models\SupplierGstWithholdingNotice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Section65AService
{
    public function applicableNotice(int $companyId, int $supplierId, string $paymentDate): ?SupplierGstWithholdingNotice
    {
        $date = Carbon::parse($paymentDate)->toDateString();
        $notices = SupplierGstWithholdingNotice::where('company_id', $companyId)
            ->whereIn('status', ['active', 'revoked'])
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->where(function ($q) use ($supplierId, $date) {
                $q->where('supplier_scope', 'all_suppliers')
                    ->orWhere(function ($specific) use ($supplierId, $date) {
                        $specific->where('supplier_scope', 'specified_suppliers')
                            ->whereHas('suppliers', fn ($supplier) => $supplier->where('suppliers.id', $supplierId)
                                ->where(fn ($period) => $period->whereNull('supplier_gst_notice_suppliers.effective_from')->orWhereDate('supplier_gst_notice_suppliers.effective_from', '<=', $date))
                                ->where(fn ($period) => $period->whereNull('supplier_gst_notice_suppliers.effective_to')->orWhereDate('supplier_gst_notice_suppliers.effective_to', '>=', $date)));
                    });
            })->orderByDesc('effective_from')->get();

        if ($notices->count() > 1) {
            throw ValidationException::withMessages(['payment_date' => 'More than one Section 65A notice applies to this supplier and payment date. Resolve overlapping notices before settling the bill.']);
        }
        return $notices->first();
    }

    public function withholdingAmount(?SupplierGstWithholdingNotice $notice, SupplierBill $bill, float $grossAllocation, float $alreadyWithheld): float
    {
        if (!$notice || !(bool) $bill->supplier_gst_registered
            || !filled(trim((string) $bill->supplier_gst_registration_number))
            || (float) $bill->gst_amount <= 0 || (float) $bill->amount <= 0) {
            return 0.0;
        }

        $invoiceTaxShare = round((float) $bill->gst_amount * ($grossAllocation / (float) $bill->amount), 2);
        $remainingTax = max(0, round((float) $bill->gst_amount - $alreadyWithheld, 2));
        return round(min($invoiceTaxShare, $remainingTax, $grossAllocation), 2);
    }

    public function assertCompanyRegistered(int $companyId): void
    {
        $company = Company::findOrFail($companyId);
        if (!$company->gst_registered || !filled(trim((string) $company->gst_registration_number))) {
            throw ValidationException::withMessages(['notice' => 'The company must have an active PNG GST registration before Section 65A withholding can be configured.']);
        }
    }
}
