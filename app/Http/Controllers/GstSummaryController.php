<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CustomerInvoice;
use App\Models\SupplierBill;
use App\Support\CompanyAuthorizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GstSummaryController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'reports.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $company = Company::findOrFail($companyId);

        $sales = CustomerInvoice::where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('gst_treatment', ['standard', 'zero_rated', 'exempt']);
        $netSalesExpression = DB::raw('COALESCE(net_amount, amount - gst_amount)');
        $totalSales = (float) (clone $sales)->sum($netSalesExpression);
        $exemptSales = (float) (clone $sales)->where('gst_treatment', 'exempt')->sum($netSalesExpression);
        $zeroRatedSales = (float) (clone $sales)->where('gst_treatment', 'zero_rated')->sum($netSalesExpression);
        $taxableSales = (float) (clone $sales)->where('gst_treatment', 'standard')->sum($netSalesExpression);
        $outputGst = (float) (clone $sales)->where('gst_treatment', 'standard')->sum('gst_amount');

        $creditNotes = DB::table('customer_credit_notes')->where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('credit_note_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('gst_treatment', ['standard', 'zero_rated', 'exempt']);
        $totalSales = round($totalSales - (float) (clone $creditNotes)->sum('net_amount'), 2);
        $exemptSales = round($exemptSales - (float) (clone $creditNotes)->where('gst_treatment', 'exempt')->sum('net_amount'), 2);
        $zeroRatedSales = round($zeroRatedSales - (float) (clone $creditNotes)->where('gst_treatment', 'zero_rated')->sum('net_amount'), 2);
        $taxableSales = round($taxableSales - (float) (clone $creditNotes)->where('gst_treatment', 'standard')->sum('net_amount'), 2);
        $outputGst = round($outputGst - (float) (clone $creditNotes)->where('gst_treatment', 'standard')->sum('gst_amount'), 2);

        $purchases = SupplierBill::where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
            ->where('input_tax_credit_eligible', true);
        $eligiblePurchases = (float) (clone $purchases)->sum('net_amount');
        $inputCredits = (float) (clone $purchases)->sum('gst_amount');

        $supplierCreditNotes = DB::table('supplier_credit_notes')
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_credit_notes.supplier_bill_id')
            ->where('supplier_credit_notes.company_id', $companyId)->where('supplier_credit_notes.status', 'posted')
            ->where('supplier_bills.input_tax_credit_eligible', true)
            ->where('supplier_credit_notes.gst_rate', '>', 0)
            ->when($branchId, fn ($q) => $q->where('supplier_credit_notes.branch_id', $branchId))
            ->whereBetween('supplier_credit_notes.credit_note_date', [$from->toDateString(), $to->toDateString()]);
        $supplierStockReturns = DB::table('supplier_stock_returns')
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_stock_returns.supplier_bill_id')
            ->where('supplier_stock_returns.company_id', $companyId)->where('supplier_stock_returns.status', 'posted')
            ->where('supplier_bills.input_tax_credit_eligible', true)
            ->when($branchId, fn ($q) => $q->where('supplier_stock_returns.branch_id', $branchId))
            ->whereBetween('supplier_stock_returns.return_date', [$from->toDateString(), $to->toDateString()]);
        $eligiblePurchases = round($eligiblePurchases
            - (float) (clone $supplierCreditNotes)->sum('supplier_credit_notes.net_amount')
            - (float) (clone $supplierStockReturns)->sum('supplier_stock_returns.net_amount'), 2);
        $inputCredits = round($inputCredits
            - (float) (clone $supplierCreditNotes)->sum('supplier_credit_notes.gst_amount')
            - (float) (clone $supplierStockReturns)->sum('supplier_stock_returns.gst_amount'), 2);

        return view('finance.gst-summary.index', [
            'company' => $company, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totalSales' => $totalSales, 'exemptSales' => $exemptSales, 'zeroRatedSales' => $zeroRatedSales,
            'taxableSales' => $taxableSales, 'outputGst' => $outputGst,
            'eligiblePurchases' => $eligiblePurchases, 'inputCredits' => $inputCredits,
            'netGst' => round($outputGst - $inputCredits, 2), 'assignedBranchId' => $branchId,
        ]);
    }
}
