<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Services\LandedCostAllocator;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceMatchingController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        $bills = SupplierBill::with(['supplier'])
            ->where('company_id', $companyId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('bill_date')
            ->paginate(25);
        $orders = PurchaseOrder::with(['supplier', 'warehouse', 'lines.product'])
            ->where('company_id', $companyId)
            ->whereIn('status', ['approved', 'partially_received', 'received'])
            ->when($branchId, fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $branchId)))
            ->latest('order_date')
            ->get();

        return view('procurement.matching.index', compact('bills', 'orders'));
    }

    public function match(Request $request, int $bill)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.invoice.match');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'invoice_type' => ['required', 'in:goods,landed_cost'],
        ]);

        DB::transaction(function () use ($data, $bill, $companyId, $branchId, $request) {
            $document = SupplierBill::where('company_id', $companyId)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($bill);
            abort_unless($document->status === 'draft', 409, 'Only draft supplier bills can be matched.');
            abort_unless(in_array($document->match_status ?? 'not_required', ['not_required', 'unmatched', 'rejected'], true), 409, 'This supplier bill already has a match in progress or has been approved.');

            $order = PurchaseOrder::where('company_id', $companyId)
                ->when($branchId, fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $branchId)))
                ->where('supplier_id', $document->supplier_id)
                ->whereIn('status', ['approved', 'partially_received', 'received'])
                ->lockForUpdate()->findOrFail($data['purchase_order_id']);
            $orderBranchId = $order->warehouse()->value('branch_id');
            if ($document->branch_id !== null && (int) $document->branch_id !== (int) $orderBranchId) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Supplier invoice and purchase order must belong to the same branch.']);
            }
            if ($document->branch_id === null && $orderBranchId !== null) {
                $document->branch_id = $orderBranchId;
            }

            if ($data['invoice_type'] === 'goods') {
                $lineData = $request->validate([
                    'lines' => ['required', 'array', 'min:1'],
                    'lines.*.purchase_order_line_id' => ['required', 'integer', 'distinct'],
                    'lines.*.quantity_billed' => ['nullable', 'numeric', 'min:0'],
                    'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
                ]);
                $lines = collect($lineData['lines']);
                $total = 0.0;
                foreach ($lines as $lineData) {
                    $quantity = (float) ($lineData['quantity_billed'] ?? 0);
                    if ($quantity <= 0) { continue; }
                    $line = PurchaseOrderLine::where('purchase_order_id', $order->id)
                        ->lockForUpdate()->findOrFail($lineData['purchase_order_line_id']);
                    $unitPrice = round((float) $lineData['unit_price'], 2);
                    $alreadyBilled = DB::table('supplier_bill_match_lines')
                        ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_bill_match_lines.supplier_bill_id')
                        ->where('supplier_bills.company_id', $companyId)
                        ->whereIn('supplier_bills.match_status', ['matched', 'approved'])
                        ->where('supplier_bills.id', '!=', $document->id)
                        ->where('supplier_bill_match_lines.purchase_order_line_id', $line->id)
                        ->sum('supplier_bill_match_lines.quantity_billed');
                    $available = round((float) $line->quantity_received - (float) $alreadyBilled, 3);
                    if ($quantity > $available + 0.0005) {
                        throw ValidationException::withMessages(['lines' => "Billed quantity exceeds received and not-yet-invoiced quantity for PO line {$line->id} ({$available})."]);
                    }
                    if (abs($unitPrice - (float) $line->unit_cost) > 0.01) {
                        throw ValidationException::withMessages(['lines' => "Unit price for PO line {$line->id} differs from the approved PO by more than K0.01. Resolve the price variance before matching."]);
                    }
                    $lineTotal = round($quantity * $unitPrice, 2);
                    $total += $lineTotal;
                    DB::table('supplier_bill_match_lines')->updateOrInsert(
                        ['supplier_bill_id' => $document->id, 'purchase_order_line_id' => $line->id],
                        ['quantity_billed' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
                if ($total <= 0) { throw ValidationException::withMessages(['lines' => 'Enter a billed quantity for at least one received PO line.']); }
                if (abs(round($total, 2) - (float) $document->amount) > 0.01) {
                    throw ValidationException::withMessages(['amount' => 'Supplier invoice total must equal the matched PO line total. Tax, freight and other charges must be entered as separate invoices until configured.']);
                }
            } else {
                $received = $order->lines()->where('quantity_received', '>', 0)->exists();
                if (!$received) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'Landed costs can only be matched to a purchase order with received goods.']);
                }
                if (DB::table('landed_cost_allocations')->where('supplier_bill_id', $document->id)->exists()) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'This supplier bill already has landed-cost allocations.']);
                }
            }

            $document->update([
                'purchase_order_id' => $order->id,
                'invoice_type' => $data['invoice_type'],
                'match_status' => 'matched',
                'matched_by' => $request->user()->id,
                'matched_at' => now(),
            ]);
        });

        return back()->with('status', 'Supplier invoice matched to the approved purchase order. It is awaiting independent approval.');
    }

    public function approve(Request $request, int $bill, LandedCostAllocator $allocator)
    {
        CompanyAuthorizer::requirePermission($request, 'procurement.invoice.approve');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $approvalData = $request->validate(['allocation_basis' => ['nullable', 'in:line_value,quantity,equal,weight,volume']]);

        DB::transaction(function () use ($bill, $allocator, $companyId, $branchId, $request, $approvalData) {
            $document = SupplierBill::where('company_id', $companyId)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($bill);
            abort_unless($document->status === 'draft' && $document->match_status === 'matched' && $document->purchase_order_id, 409, 'Only matched draft supplier invoices can be approved.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The supplier invoice creator cannot approve their own invoice.');
            abort_if((int) $document->matched_by === (int) $request->user()->id, 403, 'The user who matched the invoice cannot approve it.');

            $order = PurchaseOrder::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->purchase_order_id);
            $accounts = [];
            foreach ([['2000', 'Accounts payable', 'liability', 'credit'], ['2600', 'Goods received not invoiced', 'liability', 'credit'], ['1200', 'Inventory', 'asset', 'debit'], ['5000', 'Cost of goods sold', 'expense', 'debit']] as [$code, $name, $type, $normal]) {
                $accounts[$code] = Account::firstOrCreate(
                    ['company_id' => $companyId, 'account_code' => $code],
                    ['name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_control_account' => true, 'is_active' => true]
                );
                if ($accounts[$code]->account_type !== $type || $accounts[$code]->normal_balance !== $normal) {
                    throw ValidationException::withMessages(['bill' => "Chart account {$code} has an incompatible type. Correct the chart before posting."]);
                }
            }

            $lines = [];
            if ($document->invoice_type === 'goods') {
                $matchedTotal = (float) DB::table('supplier_bill_match_lines')->where('supplier_bill_id', $document->id)->sum('line_total');
                if (abs(round($matchedTotal, 2) - (float) $document->amount) > 0.01) {
                    throw ValidationException::withMessages(['bill' => 'The matched lines no longer reconcile to the supplier invoice total.']);
                }
                $lines[] = [$accounts['2600']->id, $document->amount, 0];
            } elseif ($document->invoice_type === 'landed_cost') {
                $allocation = $allocator->allocate($companyId, (int) $document->id, $order, (float) $document->amount, $approvalData['allocation_basis'] ?? 'line_value');
                if ($allocation['inventory'] > 0) $lines[] = [$accounts['1200']->id, $allocation['inventory'], 0];
                if ($allocation['cogs'] > 0) $lines[] = [$accounts['5000']->id, $allocation['cogs'], 0];
            } else {
                throw ValidationException::withMessages(['bill' => 'Unsupported supplier invoice type.']);
            }
            $lines[] = [$accounts['2000']->id, 0, $document->amount];

            $journal = JournalEntry::create([
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'journal_number' => 'AP-MATCH-' . $document->id . '-' . Str::upper(Str::random(5)),
                'journal_date' => $document->bill_date,
                'source' => $document->invoice_type === 'goods' ? 'supplier_invoice_match' : 'landed_cost_invoice',
                'status' => 'posted',
                'description' => 'Supplier invoice ' . $document->bill_number . ' matched to ' . $order->order_number,
                'created_by' => $document->created_by,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
            foreach ($lines as [$accountId, $debit, $credit]) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $accountId,
                    'description' => 'Supplier invoice ' . $document->bill_number,
                    'debit' => $debit,
                    'credit' => $credit,
                ]);
            }
            $document->update([
                'status' => 'posted',
                'match_status' => 'approved',
                'journal_entry_id' => $journal->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
        });

        return back()->with('status', 'Supplier invoice approved and posted to accounts payable.');
    }
}
