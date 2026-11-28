<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Support\CompanyAuthorizer;
use App\Support\InventoryLedger;
use App\Services\InventoryCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierStockReturnController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $bills = SupplierBill::with('supplier')->where('company_id', $companyId)
            ->where('status', 'posted')->where('invoice_type', 'goods')->where('match_status', 'approved')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('bill_date')->limit(40)->get();
        $matchedLines = DB::table('supplier_bill_match_lines')
            ->join('purchase_order_lines', 'purchase_order_lines.id', '=', 'supplier_bill_match_lines.purchase_order_line_id')
            ->join('products', 'products.id', '=', 'purchase_order_lines.product_id')
            ->whereIn('supplier_bill_match_lines.supplier_bill_id', $bills->pluck('id'))
            ->select('supplier_bill_match_lines.supplier_bill_id', 'supplier_bill_match_lines.purchase_order_line_id',
                'supplier_bill_match_lines.quantity_billed', 'supplier_bill_match_lines.unit_price',
                'products.sku', 'products.name')
            ->orderBy('products.name')->get()->groupBy('supplier_bill_id');
        $returns = DB::table('supplier_stock_returns')
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_stock_returns.supplier_bill_id')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_bills.supplier_id')
            ->where('supplier_stock_returns.company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('supplier_stock_returns.branch_id', $branchId))
            ->select('supplier_stock_returns.*', 'supplier_bills.bill_number', 'suppliers.name as supplier_name')
            ->orderByDesc('supplier_stock_returns.return_date')->limit(40)->get();

        return view('procurement.stock-returns.index', compact('bills', 'matchedLines', 'returns'));
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'bill_id' => ['required', 'integer', Rule::exists('supplier_bills', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->where('invoice_type', 'goods')->where('match_status', 'approved'))],
            'credit_note_number' => ['required', 'string', 'max:80', Rule::unique('supplier_stock_returns')->where(fn ($q) => $q->where('company_id', $companyId))],
            'return_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $data['lines'] = array_values(array_filter($data['lines'], fn ($line) => isset($line['quantity']) && $line['quantity'] !== ''));
        if (count($data['lines']) === 0) throw ValidationException::withMessages(['lines' => 'Enter a return quantity for at least one matched product line.']);

        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $bill = SupplierBill::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['bill_id']);
            abort_unless($bill->status === 'posted' && $bill->invoice_type === 'goods' && $bill->match_status === 'approved' && $bill->purchase_order_id, 409, 'Stock returns require a posted, approved PO-matched goods invoice.');
            if ($bill->currency !== 'PGK') throw ValidationException::withMessages(['bill_id' => 'Supplier stock returns currently support PGK only.']);
            if ($data['return_date'] < $bill->bill_date->toDateString()) throw ValidationException::withMessages(['return_date' => 'Return date cannot be earlier than the supplier invoice date.']);
            $order = PurchaseOrder::where('company_id', $companyId)->lockForUpdate()->findOrFail($bill->purchase_order_id);
            $warehouse = $order->warehouse;
            if (!$warehouse) throw ValidationException::withMessages(['bill_id' => 'The original purchase order warehouse is unavailable.']);
            if ($branchId !== null && (int) $warehouse->branch_id !== (int) $branchId) abort(403);

            $company = Company::findOrFail($companyId);
            $headerNet = 0.0;
            $headerGst = 0.0;
            $lineRows = [];
            foreach ($data['lines'] as $row) {
                $poLine = PurchaseOrderLine::where('purchase_order_id', $order->id)
                    ->lockForUpdate()->findOrFail($row['purchase_order_line_id']);
                $matched = DB::table('supplier_bill_match_lines')->where('supplier_bill_id', $bill->id)
                    ->where('purchase_order_line_id', $poLine->id)->first();
                if (!$matched) throw ValidationException::withMessages(['lines' => 'Every returned item must belong to the original matched supplier invoice.']);
                $quantity = round((float) $row['quantity'], 3);
                $alreadyReturned = (float) DB::table('supplier_stock_return_lines')
                    ->join('supplier_stock_returns', 'supplier_stock_returns.id', '=', 'supplier_stock_return_lines.supplier_stock_return_id')
                    ->where('supplier_stock_returns.company_id', $companyId)
                    ->where('supplier_stock_returns.supplier_bill_id', $bill->id)
                    ->where('supplier_stock_return_lines.purchase_order_line_id', $poLine->id)
                    ->whereIn('supplier_stock_returns.status', ['draft', 'posted'])
                    ->sum('supplier_stock_return_lines.quantity');
                $billRemaining = round((float) $matched->quantity_billed - $alreadyReturned, 3);
                if ($quantity > $billRemaining + 0.0005) throw ValidationException::withMessages(['lines' => "Return quantity exceeds the original billed quantity remaining ({$billRemaining})."]);
                $product = Product::where('company_id', $companyId)->whereKey($poLine->product_id)->lockForUpdate()->firstOrFail();
                $onHand = InventoryLedger::onHand($companyId, (int) $warehouse->id, (int) $product->id);
                $draftReserved = (float) DB::table('supplier_stock_return_lines')
                    ->join('supplier_stock_returns', 'supplier_stock_returns.id', '=', 'supplier_stock_return_lines.supplier_stock_return_id')
                    ->where('supplier_stock_returns.company_id', $companyId)
                    ->where('supplier_stock_returns.warehouse_id', $warehouse->id)
                    ->where('supplier_stock_return_lines.product_id', $product->id)
                    ->where('supplier_stock_returns.status', 'draft')
                    ->sum('supplier_stock_return_lines.quantity');
                if ($quantity > round($onHand - $draftReserved, 3) + 0.0005) throw ValidationException::withMessages(['lines' => "Insufficient unreserved stock for {$product->name}. Available: ".round($onHand-$draftReserved,3)."."]);
                $unitPrice = (float) $matched->unit_price;
                $net = round($quantity * $unitPrice, 2);
                $gstRate = (float) ($bill->input_tax_credit_eligible ? $bill->gst_rate : 0);
                if ((float) $bill->gst_amount > 0 && $gstRate <= 0) throw ValidationException::withMessages(['bill_id' => 'The original GST input credit cannot be reversed because its tax treatment is incomplete.']);
                if ($gstRate > 0 && (!(bool) $company->gst_registered || !(bool) $bill->supplier_gst_registered || !filled(trim((string) $bill->tax_invoice_reference)))) {
                    throw ValidationException::withMessages(['bill_id' => 'Company registration and supplier tax-invoice evidence must remain valid to reverse the original input GST.']);
                }
                $gst = round($net * $gstRate / 100, 2);
                $gross = round($net + $gst, 2);
                $lineRows[] = ['purchase_order_line_id' => $poLine->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'net_amount' => $net, 'gst_rate' => $gstRate, 'gst_amount' => $gst, 'total_amount' => $gross];
                $headerNet += $net;
                $headerGst += $gst;
            }

            $returnId = DB::table('supplier_stock_returns')->insertGetId([
                'company_id' => $companyId, 'branch_id' => $bill->branch_id,
                'supplier_bill_id' => $bill->id, 'warehouse_id' => $warehouse->id,
                'credit_note_number' => $data['credit_note_number'], 'return_date' => $data['return_date'],
                'description' => $data['description'], 'net_amount' => round($headerNet, 2),
                'gst_amount' => round($headerGst, 2), 'total_amount' => round($headerNet + $headerGst, 2),
                'currency' => 'PGK', 'status' => 'draft', 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lineRows as $line) {
                DB::table('supplier_stock_return_lines')->insert($line + [
                    'supplier_stock_return_id' => $returnId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('status', 'Supplier stock return and credit note saved as a draft. Stock and AP are unchanged until independent approval.');
    }

    public function post(Request $request, int $return)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($return, $companyId, $branchId, $request) {
            $document = DB::table('supplier_stock_returns')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($return);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft supplier stock returns can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The stock return creator cannot post their own return.');
            $bill = SupplierBill::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->supplier_bill_id);
            abort_unless($bill->status === 'posted' && $bill->invoice_type === 'goods' && $bill->match_status === 'approved', 409, 'Original supplier invoice must remain posted and matched.');
            if ($document->return_date < $bill->bill_date->toDateString()) throw ValidationException::withMessages(['return' => 'Return date cannot be earlier than the original invoice date.']);
            $company = Company::findOrFail($companyId);
            if ((float) $document->gst_amount > 0 && (!(bool) $company->gst_registered || !(bool) $bill->supplier_gst_registered || !(bool) $bill->input_tax_credit_eligible || !filled(trim((string) $bill->supplier_gst_registration_number)) || !filled(trim((string) $bill->tax_invoice_reference)))) {
                throw ValidationException::withMessages(['return' => 'The original input GST credit cannot be reversed because company or supplier tax-invoice evidence is no longer valid.']);
            }
            $order = PurchaseOrder::where('company_id', $companyId)->lockForUpdate()->findOrFail($bill->purchase_order_id);
            $warehouse = $order->warehouse;
            abort_unless((int) $warehouse->id === (int) $document->warehouse_id, 409, 'Return warehouse must match the original purchase order.');

            $paid = (float) DB::table('supplier_payment_allocations')
                ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)
                ->where('supplier_payments.company_id', $companyId)->whereIn('supplier_payments.status', ['draft', 'posted'])
                ->sum('supplier_payment_allocations.amount');
            $otherExpenseCredits = (float) DB::table('supplier_credit_notes')->where('company_id', $companyId)
                ->where('supplier_bill_id', $bill->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $otherStockCredits = (float) DB::table('supplier_stock_returns')->where('company_id', $companyId)
                ->where('supplier_bill_id', $bill->id)->whereIn('status', ['draft', 'posted'])->where('id', '!=', $document->id)->sum('total_amount');
            if ((float) $document->total_amount > round((float) $bill->amount - $paid - $otherExpenseCredits - $otherStockCredits, 2) + 0.009) {
                throw ValidationException::withMessages(['return' => 'Stock credit note would exceed the supplier bill outstanding AP balance.']);
            }

            $lines = DB::table('supplier_stock_return_lines')->where('supplier_stock_return_id', $document->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['return' => 'A stock return must contain at least one product line.']);
            $lineNet = round((float) $lines->sum('net_amount'), 2);
            $lineGst = round((float) $lines->sum('gst_amount'), 2);
            $lineGross = round((float) $lines->sum('total_amount'), 2);
            if (abs($lineNet - (float) $document->net_amount) > 0.009 || abs($lineGst - (float) $document->gst_amount) > 0.009 || abs($lineGross - (float) $document->total_amount) > 0.009 || abs($lineNet + $lineGst - $lineGross) > 0.009) {
                throw ValidationException::withMessages(['return' => 'Supplier stock return line totals do not reconcile to the credit note header.']);
            }
            $movementValues = [];
            $costTotal = 0.0;
            foreach ($lines as $line) {
                $poLine = PurchaseOrderLine::where('purchase_order_id', $order->id)->whereKey($line->purchase_order_line_id)->firstOrFail();
                Product::where('company_id', $companyId)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                $matched = DB::table('supplier_bill_match_lines')->where('supplier_bill_id', $bill->id)->where('purchase_order_line_id', $poLine->id)->firstOrFail();
                $returnedOther = (float) DB::table('supplier_stock_return_lines')
                    ->join('supplier_stock_returns', 'supplier_stock_returns.id', '=', 'supplier_stock_return_lines.supplier_stock_return_id')
                    ->where('supplier_stock_returns.company_id', $companyId)->where('supplier_stock_returns.supplier_bill_id', $bill->id)
                    ->where('supplier_stock_return_lines.purchase_order_line_id', $poLine->id)
                    ->whereIn('supplier_stock_returns.status', ['draft', 'posted'])->where('supplier_stock_returns.id', '!=', $document->id)
                    ->sum('supplier_stock_return_lines.quantity');
                if ((float) $line->quantity > round((float) $matched->quantity_billed - $returnedOther, 3) + 0.0005) {
                    throw ValidationException::withMessages(['return' => 'Return quantity exceeds the remaining invoiced quantity.']);
                }
                $onHand = InventoryLedger::onHand($companyId, (int) $warehouse->id, (int) $line->product_id);
                $draftReserved = (float) DB::table('supplier_stock_return_lines')
                    ->join('supplier_stock_returns', 'supplier_stock_returns.id', '=', 'supplier_stock_return_lines.supplier_stock_return_id')
                    ->where('supplier_stock_returns.company_id', $companyId)->where('supplier_stock_returns.warehouse_id', $warehouse->id)
                    ->where('supplier_stock_return_lines.product_id', $line->product_id)->where('supplier_stock_returns.status', 'draft')
                    ->where('supplier_stock_returns.id', '!=', $document->id)->sum('supplier_stock_return_lines.quantity');
                if ((float) $line->quantity > round($onHand - $draftReserved, 3) + 0.0005) {
                    throw ValidationException::withMessages(['return' => 'Stock on hand has changed; the return cannot be posted until quantities are reconciled.']);
                }

                $movement = StockMovement::create([
                    'company_id' => $companyId, 'warehouse_id' => $warehouse->id, 'product_id' => $line->product_id,
                    'movement_type' => 'supplier_return', 'quantity' => $line->quantity, 'unit_cost' => 0,
                    'reference_type' => 'supplier_stock_return_line', 'reference_id' => $line->id,
                    'occurred_at' => now(), 'created_by' => $request->user()->id,
                    'notes' => 'Supplier stock return '.$document->credit_note_number,
                ]);
                app(InventoryCostingService::class)->apply($movement);
                $movement = $movement->fresh();
                $movementValues[] = $movement;
                $costTotal += (float) $movement->cost_value;
                DB::table('supplier_stock_return_lines')->where('id', $line->id)->update(['stock_movement_id' => $movement->id, 'updated_at' => now()]);
            }

            $ap = Account::where('company_id', $companyId)->where('account_code', '2000')->firstOrFail();
            $inventory = Account::where('company_id', $companyId)->where('account_code', '1200')->firstOrFail();
            abort_unless($ap->account_type === 'liability' && $ap->normal_balance === 'credit', 422, 'AP control account 2000 must be a credit-normal liability.');
            abort_unless($inventory->account_type === 'asset' && $inventory->normal_balance === 'debit', 422, 'Inventory account 1200 must be a debit-normal asset.');
            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'SUP-RET-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->return_date, 'source' => 'supplier_stock_return', 'status' => 'posted',
                'description' => 'Supplier stock credit note '.$document->credit_note_number.' · '.$document->description,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ap->id, 'description' => 'Supplier credit note '.$document->credit_note_number, 'debit' => $document->total_amount, 'credit' => 0]);
            if ((float) $document->gst_amount > 0) {
                $inputTax = Account::where('company_id', $companyId)->where('account_code', '1300')->firstOrFail();
                abort_unless($inputTax->account_type === 'asset' && $inputTax->normal_balance === 'debit', 422, 'GST input tax account 1300 must be a debit-normal asset.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $inputTax->id, 'description' => 'Reverse supplier input GST', 'debit' => 0, 'credit' => $document->gst_amount]);
            }
            if ($costTotal > 0) JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $inventory->id, 'description' => 'Remove returned stock at carrying value', 'debit' => 0, 'credit' => round($costTotal, 2)]);
            $variance = round((float) $document->net_amount - $costTotal, 2);
            if ($variance > 0.009) {
                $gain = Account::firstOrCreate(['company_id' => $companyId, 'account_code' => '4295'], ['name' => 'Supplier return purchase price variance gain', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
                abort_unless($gain->account_type === 'income' && $gain->normal_balance === 'credit', 422, 'Purchase price variance gain account 4295 is incompatible.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $gain->id, 'description' => 'Supplier return purchase price variance', 'debit' => 0, 'credit' => $variance]);
            } elseif ($variance < -0.009) {
                $loss = Account::firstOrCreate(['company_id' => $companyId, 'account_code' => '6595'], ['name' => 'Supplier return purchase price variance loss', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
                abort_unless($loss->account_type === 'expense' && $loss->normal_balance === 'debit', 422, 'Purchase price variance loss account 6595 is incompatible.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $loss->id, 'description' => 'Supplier return purchase price variance', 'debit' => abs($variance), 'credit' => 0]);
            }
            foreach ($movementValues as $movement) {
                DB::table('stock_movement_journals')->insert([
                    'stock_movement_id' => $movement->id, 'journal_entry_id' => $journal->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('supplier_stock_returns')->where('id', $document->id)->update([
                'status' => 'posted', 'journal_entry_id' => $journal->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Supplier inventory credit note posted. Stock, inventory valuation, GST input credit and AP have been adjusted.');
    }

    public function cancel(Request $request, int $return)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($return, $companyId, $branchId) {
            $document = DB::table('supplier_stock_returns')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($return);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft supplier stock returns can be cancelled.');
            DB::table('supplier_stock_returns')->where('id', $document->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
        return back()->with('status', 'Draft supplier stock return cancelled; its stock and AP reservations have been released.');
    }
}
