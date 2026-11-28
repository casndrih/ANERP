<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\CustomerSalesReturn;
use App\Models\CustomerSalesReturnLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use App\Support\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerSalesReturnController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        $invoices = CustomerInvoice::with(['customer', 'lines.product'])
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->whereNotNull('sales_order_id')
            ->where('currency', 'PGK')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('invoice_date')->limit(100)->get();

        $returns = CustomerSalesReturn::with(['customer', 'invoice', 'warehouse', 'lines.product', 'creditNote'])
            ->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('return_date')->limit(50)->get();

        return view('sales.returns.index', [
            'invoices' => $invoices,
            'returns' => $returns,
            'warehouses' => Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('name')->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('customer_invoices', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->whereNotNull('sales_order_id')->where('currency', 'PGK'))],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'return_number' => ['required', 'string', 'max:60', Rule::unique('customer_sales_returns')->where(fn ($q) => $q->where('company_id', $companyId))],
            'return_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'distinct', 'exists:customer_invoice_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.condition' => ['required', Rule::in(['resellable', 'damaged', 'defective'])],
            'lines.*.condition_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $invoice = CustomerInvoice::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['invoice_id']);
            abort_unless($invoice->status === 'posted' && $invoice->sales_order_id, 409, 'Sales returns require a posted invoice generated from an approved sales order.');
            abort_unless($invoice->currency === 'PGK', 422, 'Sales returns currently support PGK invoices only.');
            if ($data['return_date'] < $invoice->invoice_date->toDateString()) {
                throw ValidationException::withMessages(['return_date' => 'Return date cannot be earlier than the original invoice date.']);
            }

            $warehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['warehouse_id']);
            if ((int) ($warehouse->branch_id ?? 0) !== (int) ($invoice->branch_id ?? 0)) {
                throw ValidationException::withMessages(['warehouse_id' => 'Returned goods must be received into an active warehouse in the original invoice branch.']);
            }

            $creditNotesReserved = (float) CustomerCreditNote::where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $draftReturnsReserved = (float) CustomerSalesReturn::where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->where('status', 'draft')->sum('total_amount');

            $returnLines = [];
            $netTotal = 0.0;
            $gstTotal = 0.0;
            $grossTotal = 0.0;

            foreach ($data['lines'] as $row) {
                $invoiceLine = CustomerInvoiceLine::where('customer_invoice_id', $invoice->id)
                    ->lockForUpdate()->findOrFail($row['invoice_line_id']);
                if (!$invoiceLine->product_id) {
                    throw ValidationException::withMessages(['lines' => 'Only invoiced stock products can be returned through the item-level stock return workflow.']);
                }

                $prior = DB::table('customer_sales_return_lines as l')
                    ->join('customer_sales_returns as r', 'r.id', '=', 'l.customer_sales_return_id')
                    ->where('r.company_id', $companyId)->where('r.customer_invoice_id', $invoice->id)
                    ->where('l.customer_invoice_line_id', $invoiceLine->id)
                    ->whereIn('r.status', ['draft', 'posted'])
                    ->selectRaw('COALESCE(SUM(l.quantity),0) as quantity, COALESCE(SUM(l.net_amount),0) as net_amount, COALESCE(SUM(l.gst_amount),0) as gst_amount')
                    ->first();
                $remainingQty = round((float) $invoiceLine->quantity - (float) $prior->quantity, 3);
                $quantity = round((float) $row['quantity'], 3);
                if ($quantity > $remainingQty + 0.0005) {
                    throw ValidationException::withMessages(['lines' => 'Returned quantity exceeds the unreturned quantity on invoice line '.$invoiceLine->id.'.']);
                }

                $remainingNet = round((float) $invoiceLine->net_amount - (float) $prior->net_amount, 2);
                $remainingGst = round((float) $invoiceLine->gst_amount - (float) $prior->gst_amount, 2);
                $isFinalQuantity = abs($quantity - $remainingQty) <= 0.0005;
                $net = $isFinalQuantity ? $remainingNet : round($remainingNet * $quantity / $remainingQty, 2);
                $gst = $isFinalQuantity ? $remainingGst : round($remainingGst * $quantity / $remainingQty, 2);
                $total = round($net + $gst, 2);

                $returnLines[] = [
                    'customer_invoice_line_id' => $invoiceLine->id,
                    'product_id' => $invoiceLine->product_id,
                    'quantity' => $quantity,
                    'condition' => $row['condition'],
                    'condition_notes' => $row['condition_notes'] ?? null,
                    'unit_price' => $invoiceLine->unit_price,
                    'net_amount' => $net,
                    'gst_rate' => $invoiceLine->gst_rate,
                    'gst_amount' => $gst,
                    'total_amount' => $total,
                ];
                $netTotal += $net;
                $gstTotal += $gst;
                $grossTotal += $total;
            }

            $grossTotal = round($grossTotal, 2);
            $remainingInvoiceValue = round((float) $invoice->amount - $creditNotesReserved - $draftReturnsReserved, 2);
            if ($grossTotal <= 0 || $grossTotal > $remainingInvoiceValue + 0.009) {
                throw ValidationException::withMessages(['lines' => 'Return value exceeds the uncredited value remaining on the original invoice.']);
            }

            $salesReturn = CustomerSalesReturn::create([
                'company_id' => $companyId,
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'customer_invoice_id' => $invoice->id,
                'warehouse_id' => $warehouse->id,
                'return_number' => $data['return_number'],
                'return_date' => $data['return_date'],
                'reason' => $data['reason'],
                'gst_treatment' => $invoice->gst_treatment ?? 'out_of_scope',
                'gst_rate' => $invoice->gst_rate ?? 0,
                'net_amount' => round($netTotal, 2),
                'gst_amount' => round($gstTotal, 2),
                'total_amount' => $grossTotal,
                'currency' => 'PGK',
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $salesReturn->lines()->createMany($returnLines);
        });

        return back()->with('status', 'Sales return saved as a draft. Returned quantities are reserved; an independent finance user must post the return and credit note.');
    }

    public function cancel(Request $request, int $salesReturn)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($salesReturn, $companyId, $branchId) {
            $document = CustomerSalesReturn::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($salesReturn);
            abort_unless($document->status === 'draft', 409, 'Only draft sales returns can be cancelled.');
            $document->update(['status' => 'cancelled']);
        });

        return back()->with('status', 'Draft sales return cancelled; its reserved return quantity and invoice value have been released.');
    }

    public function post(Request $request, int $salesReturn)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($salesReturn, $companyId, $branchId, $request) {
            $document = CustomerSalesReturn::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->with('lines')->lockForUpdate()->findOrFail($salesReturn);
            abort_unless($document->status === 'draft', 409, 'Only draft sales returns can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The sales return creator cannot post their own return.');

            $invoice = CustomerInvoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->customer_invoice_id);
            abort_unless($invoice->status === 'posted' && (int) $invoice->customer_id === (int) $document->customer_id, 409, "Sales return must reference the same customer's posted invoice.");
            abort_unless($invoice->currency === 'PGK' && $document->currency === 'PGK', 422, 'Sales returns currently support PGK only.');
            abort_unless((int) ($invoice->branch_id ?? 0) === (int) ($document->branch_id ?? 0), 409, 'Sales return and original invoice branch attribution must match.');

            $warehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $document->branch_id)->lockForUpdate()->findOrFail($document->warehouse_id);

            $otherCredits = (float) CustomerCreditNote::where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $otherDraftReturns = (float) CustomerSalesReturn::where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->where('status', 'draft')
                ->where('id', '!=', $document->id)->sum('total_amount');
            if ((float) $document->total_amount > round((float) $invoice->amount - $otherCredits - $otherDraftReturns, 2) + 0.009) {
                throw ValidationException::withMessages(['sales_return' => 'Posting would exceed the remaining value of the original invoice after existing credit notes and draft returns.']);
            }

            foreach ($document->lines as $line) {
                $invoiceLine = CustomerInvoiceLine::where('customer_invoice_id', $invoice->id)
                    ->lockForUpdate()->findOrFail($line->customer_invoice_line_id);
                abort_unless((int) $invoiceLine->product_id === (int) $line->product_id, 409, 'Returned product no longer matches the original invoice line.');

                $previousQty = (float) DB::table('customer_sales_return_lines as l')
                    ->join('customer_sales_returns as r', 'r.id', '=', 'l.customer_sales_return_id')
                    ->where('r.company_id', $companyId)->where('r.customer_invoice_id', $invoice->id)
                    ->where('l.customer_invoice_line_id', $invoiceLine->id)
                    ->whereIn('r.status', ['draft', 'posted'])->where('r.id', '!=', $document->id)
                    ->sum('l.quantity');
                if ((float) $line->quantity > (float) $invoiceLine->quantity - $previousQty + 0.0005) {
                    throw ValidationException::withMessages(['sales_return' => 'Posting would return more than the invoiced quantity.']);
                }

                if ($line->condition !== 'resellable') {
                    continue;
                }

                $issue = StockMovement::where('company_id', $companyId)
                    ->where('reference_type', 'sales_order')->where('reference_id', $invoice->sales_order_id)
                    ->where('product_id', $line->product_id)->where('movement_type', 'issue')
                    ->lockForUpdate()->first();
                if (!$issue || (float) $issue->quantity <= 0) {
                    throw ValidationException::withMessages(['inventory' => 'Original sales issue movement was not found; reconcile the inventory ledger before restocking this return.']);
                }
                $previousResellable = (float) DB::table('customer_sales_return_lines as l')
                    ->join('customer_sales_returns as r', 'r.id', '=', 'l.customer_sales_return_id')
                    ->where('r.company_id', $companyId)->where('r.customer_invoice_id', $invoice->id)
                    ->where('l.customer_invoice_line_id', $invoiceLine->id)->where('l.condition', 'resellable')
                    ->whereIn('r.status', ['draft', 'posted'])->where('r.id', '!=', $document->id)
                    ->sum('l.quantity');
                if ((float) $line->quantity > (float) $issue->quantity - $previousResellable + 0.0005) {
                    throw ValidationException::withMessages(['inventory' => 'Resellable return quantity exceeds the original inventory issue quantity.']);
                }

                $originalCostValue = (float) ($issue->cost_value ?? ((float) $issue->quantity * (float) $issue->unit_cost));
                $unitCost = (float) $issue->quantity > 0 ? $originalCostValue / (float) $issue->quantity : (float) $issue->unit_cost;
                $movementCost = round($unitCost * (float) $line->quantity, 2);
                $movement = InventoryLedger::record(
                    $companyId,
                    (int) $warehouse->id,
                    (int) $line->product_id,
                    'customer_return',
                    (float) $line->quantity,
                    round($unitCost, 2),
                    $request->user()->id,
                    'customer_sales_return',
                    $document->id,
                    'Resellable customer return '.$document->return_number,
                    $movementCost
                );
                $line->update([
                    'original_issue_movement_id' => $issue->id,
                    'stock_movement_id' => $movement->id,
                ]);
            }

            $postedReceipts = (float) DB::table('customer_receipt_allocations')
                ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                ->where('customer_receipts.company_id', $companyId)->where('customer_receipts.status', 'posted')
                ->where('customer_receipt_allocations.customer_invoice_id', $invoice->id)
                ->sum('customer_receipt_allocations.amount');
            $postedCredits = (float) CustomerCreditNote::where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->where('status', 'posted')->sum('amount');
            $arOutstanding = max(0, round((float) $invoice->amount - $postedReceipts - $postedCredits, 2));
            $arCredit = min((float) $document->total_amount, $arOutstanding);
            $depositCredit = round((float) $document->total_amount - $arCredit, 2);

            $revenue = Account::where('company_id', $companyId)->whereKey($invoice->revenue_account_id)->firstOrFail();
            $ar = Account::where('company_id', $companyId)->where('account_code', '1100')->firstOrFail();
            abort_unless($revenue->account_type === 'income' && $revenue->normal_balance === 'credit', 422, 'Invoice revenue account must be a credit-normal income account.');
            abort_unless($ar->account_type === 'asset' && $ar->normal_balance === 'debit', 422, 'AR control account 1100 must be a debit-normal asset.');

            $journal = JournalEntry::create([
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->return_date,
                'source' => 'customer_sales_return',
                'status' => 'posted',
                'description' => 'Sales return '.$document->return_number.' · '.$document->reason,
                'created_by' => $document->created_by,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            JournalLine::create([
                'journal_entry_id' => $journal->id, 'account_id' => $revenue->id,
                'description' => 'Reverse sales revenue for '.$document->return_number,
                'debit' => $document->net_amount, 'credit' => 0,
            ]);
            if ((float) $document->gst_amount > 0) {
                $outputTax = Account::where('company_id', $companyId)->where('account_code', '2100')->firstOrFail();
                abort_unless($outputTax->account_type === 'liability' && $outputTax->normal_balance === 'credit', 422, 'GST output account 2100 must be a credit-normal liability.');
                JournalLine::create([
                    'journal_entry_id' => $journal->id, 'account_id' => $outputTax->id,
                    'description' => 'Reverse output GST for '.$document->return_number,
                    'debit' => $document->gst_amount, 'credit' => 0,
                ]);
            }
            if ($arCredit > 0) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id, 'account_id' => $ar->id,
                    'description' => 'Reduce customer receivable for '.$document->return_number,
                    'debit' => 0, 'credit' => $arCredit,
                ]);
            }
            if ($depositCredit > 0) {
                $deposits = Account::firstOrCreate(
                    ['company_id' => $companyId, 'account_code' => '2500'],
                    ['name' => 'Customer deposits and advances', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]
                );
                abort_unless($deposits->account_type === 'liability' && $deposits->normal_balance === 'credit', 422, 'Customer deposits control account 2500 must be a credit-normal liability.');
                JournalLine::create([
                    'journal_entry_id' => $journal->id, 'account_id' => $deposits->id,
                    'description' => 'Customer return credit held as an advance',
                    'debit' => 0, 'credit' => $depositCredit,
                ]);
            }

            $creditNote = CustomerCreditNote::create([
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'customer_id' => $document->customer_id,
                'customer_invoice_id' => $invoice->id,
                'source_sales_return_id' => $document->id,
                'journal_entry_id' => $journal->id,
                'credit_note_number' => 'SR-CN-'.$document->return_number,
                'credit_note_date' => $document->return_date,
                'description' => 'Sales return '.$document->return_number.' · '.$document->reason,
                'amount' => $document->total_amount,
                'deposit_amount' => $depositCredit,
                'net_amount' => $document->net_amount,
                'gst_amount' => $document->gst_amount,
                'gst_rate' => $document->gst_rate,
                'gst_treatment' => $document->gst_treatment,
                'currency' => 'PGK',
                'status' => 'posted',
                'created_by' => $document->created_by,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            $document->update([
                'status' => 'posted',
                'customer_credit_note_id' => $creditNote->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
        });

        return back()->with('status', 'Sales return posted. Resellable goods were received into inventory at original issue cost, and the GST-adjusted customer credit note was posted.');
    }
}
