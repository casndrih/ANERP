<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\PngProcurementTaxCalculator;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportAssessmentController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $orders = PurchaseOrder::with(['supplier', 'warehouse'])
            ->where('company_id', $companyId)->whereIn('status', ['approved', 'partially_received', 'received'])
            ->whereHas('lines', fn ($q) => $q->where('quantity_received', '>', 0))
            ->when($branchId, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('branch_id', $branchId)))
            ->latest('order_date')->limit(60)->get();
        $bills = SupplierBill::with(['supplier'])
            ->where('company_id', $companyId)->where('gst_source', 'import')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('bill_date')->limit(40)->get();

        return view('procurement.import-assessments.index', [
            'orders' => $orders,
            'suppliers' => Supplier::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'bills' => $bills,
            'company' => Company::findOrFail($companyId),
        ]);
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->whereIn('status', ['approved', 'partially_received', 'received']))],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'customs_assessment_reference' => ['required', 'string', 'max:120'],
            'import_declaration_number' => ['required', 'string', 'max:120'],
            'hs_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'customs_value' => ['required', 'numeric', 'min:0'],
            'customs_duty_amount' => ['required', 'numeric', 'min:0'],
            'verified_duty_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'excise_amount' => ['nullable', 'numeric', 'min:0'],
            'freight_insurance_amount' => ['nullable', 'numeric', 'min:0'],
            'gst_exempt' => ['nullable', 'boolean'],
            'input_tax_credit_eligible' => ['nullable', 'boolean'],
            'assessment_date' => ['required', 'date'],
            'tariff_source_url' => ['required', 'url', 'max:500'],
            'tariff_verified_on' => ['required', 'date'],
        ]);

        $company = Company::findOrFail($companyId);
        $eligible = (bool) ($data['input_tax_credit_eligible'] ?? false);
        $gstExempt = (bool) ($data['gst_exempt'] ?? false);
        if ($eligible && !(bool) $company->gst_registered) {
            throw ValidationException::withMessages(['input_tax_credit_eligible' => 'The importing company must be GST-registered before import GST can be claimed as an input credit.']);
        }
        if ($eligible && $gstExempt) {
            throw ValidationException::withMessages(['input_tax_credit_eligible' => 'GST-exempt imports cannot claim import GST input credits.']);
        }

        $order = PurchaseOrder::with(['warehouse', 'lines'])->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('branch_id', $branchId)))
            ->findOrFail($data['purchase_order_id']);
        if (!$order->lines->contains(fn ($line) => (float) $line->quantity_received > 0)) {
            throw ValidationException::withMessages(['purchase_order_id' => 'The purchase order must have received stock before import charges can be allocated.']);
        }

        $calculator = new PngProcurementTaxCalculator();
        $assessment = $calculator->importAssessmentFromCustomsEntry(
            (float) $data['customs_value'],
            (float) $data['customs_duty_amount'],
            (float) ($data['excise_amount'] ?? 0),
            (float) ($data['freight_insurance_amount'] ?? 0),
            $gstExempt
        );
        $netBorderCosts = round($assessment['customs_duty'] + $assessment['excise'] + $assessment['freight_insurance'], 2);
        $gstAmount = $eligible ? $assessment['import_gst'] : 0.0;
        $netAmount = round($netBorderCosts + ($eligible ? 0 : $assessment['import_gst']), 2);
        $grossAmount = round($netAmount + $gstAmount, 2);

        DB::transaction(function () use ($data, $companyId, $branchId, $order, $assessment, $eligible, $gstExempt, $gstAmount, $netAmount, $grossAmount, $request) {
            $supplier = Supplier::where('company_id', $companyId)->where('is_active', true)->findOrFail($data['supplier_id']);
            $warehouse = $order->warehouse;
            abort_unless($warehouse && (!$branchId || (int) $warehouse->branch_id === (int) $branchId), 403);
            $expense = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => '6400'],
                ['name' => 'Purchases and landed costs', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]
            );
            abort_unless($expense->account_type === 'expense' && $expense->normal_balance === 'debit', 422, 'Account 6400 must be a debit-normal expense account.');

            $billNumber = 'IMP-'.Str::upper(Str::slug($data['customs_assessment_reference'], '-')).'-'.Str::upper(Str::random(4));
            SupplierBill::create([
                'company_id' => $companyId, 'branch_id' => $warehouse->branch_id,
                'supplier_id' => $supplier->id, 'expense_account_id' => $expense->id,
                'purchase_order_id' => $order->id, 'bill_number' => $billNumber,
                'bill_date' => $data['assessment_date'], 'due_date' => $data['assessment_date'],
                'amount' => $grossAmount, 'net_amount' => $netAmount,
                'gst_treatment' => $gstExempt ? 'exempt' : 'standard',
                'gst_rate' => $eligible ? $assessment['import_gst_rate'] : 0,
                'gst_amount' => $gstAmount, 'supplier_gst_registered' => false,
                'supplier_gst_registration_number' => null,
                'tax_invoice_reference' => $data['customs_assessment_reference'],
                'input_tax_credit_eligible' => $eligible,
                'gst_source' => 'import',
                'customs_assessment_reference' => $data['customs_assessment_reference'],
                'import_declaration_number' => $data['import_declaration_number'],
                'hs_code' => $data['hs_code'],
                'verified_duty_rate' => $data['verified_duty_rate'] ?? null,
                'customs_value' => $assessment['customs_value'],
                'gst_base' => $assessment['gst_base'],
                'customs_duty_amount' => $assessment['customs_duty'],
                'excise_amount' => $assessment['excise'],
                'freight_insurance_amount' => $assessment['freight_insurance'],
                'import_gst_amount' => $assessment['import_gst'],
                'tariff_source_url' => $data['tariff_source_url'],
                'tariff_verified_on' => $data['tariff_verified_on'],
                'currency' => 'PGK',
                'description' => 'Import customs assessment '.$data['customs_assessment_reference'].' · HS '.$data['hs_code'].' · Import GST base K'.number_format($assessment['gst_base'], 2),
                'status' => 'draft', 'invoice_type' => 'landed_cost', 'match_status' => 'unmatched',
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('procurement.matching.index')->with('status', 'Import assessment saved as a landed-cost supplier bill. Match it to the received PO and select the allocation basis before independent approval.');
    }
}
