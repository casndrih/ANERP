<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\PngProcurementTaxCalculator;
use App\Support\CompanyAuthorizer;
use App\Support\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $orders = SalesOrder::with(['customer', 'warehouse', 'lines.product', 'invoice'])
            ->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('order_date')->paginate(25);

        return view('sales.orders.index', [
            'orders' => $orders,
            'customers' => Customer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'products' => Product::where('company_id', $companyId)->where('is_active', true)->where('track_stock', true)->orderBy('name')->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'order_number' => ['required', 'string', 'max:80', Rule::unique('sales_orders')->where(fn ($q) => $q->where('company_id', $companyId))],
            'order_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'gst_treatment' => ['required', Rule::in(['standard', 'zero_rated', 'exempt', 'out_of_scope'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true)->where('track_stock', true))],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $branchId = CompanyAuthorizer::activeBranchId($request);
        $warehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($data['warehouse_id']);
        $company = Company::findOrFail($companyId);
        $calculator = new PngProcurementTaxCalculator();

        DB::transaction(function () use ($data, $companyId, $warehouse, $company, $calculator, $request) {
            $order = SalesOrder::create([
                'company_id' => $companyId, 'branch_id' => $warehouse->branch_id,
                'customer_id' => $data['customer_id'], 'warehouse_id' => $warehouse->id,
                'order_number' => $data['order_number'], 'order_date' => $data['order_date'],
                'due_date' => $data['due_date'] ?? null, 'status' => 'draft',
                'gst_treatment' => $data['gst_treatment'], 'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $netTotal = 0.0;
            $gstTotal = 0.0;
            $grossTotal = 0.0;
            $gstRate = 0.0;
            foreach ($data['lines'] as $row) {
                $product = Product::where('company_id', $companyId)->where('is_active', true)
                    ->where('track_stock', true)->findOrFail($row['product_id']);
                $net = round((float) $row['quantity'] * (float) $row['unit_price'], 2);
                $tax = $calculator->domesticSupply($net, $data['gst_treatment'], (bool) $company->gst_registered);
                $quantity = round((float) $row['quantity'], 3);
                $gstTotal += $tax['gst_amount'];
                $netTotal += $net;
                $grossTotal += $tax['total'];
                $gstRate = $tax['gst_rate'];
                $order->lines()->create([
                    'product_id' => $product->id, 'quantity' => $quantity,
                    'unit_price' => round((float) $row['unit_price'], 2),
                    'net_amount' => $net, 'gst_rate' => $tax['gst_rate'],
                    'gst_amount' => $tax['gst_amount'], 'total_amount' => $tax['total'],
                ]);
            }
            $order->update([
                'net_amount' => round($netTotal, 2), 'gst_rate' => $gstRate,
                'gst_amount' => round($gstTotal, 2), 'total_amount' => round($grossTotal, 2),
            ]);
        });

        return back()->with('status', 'Sales order saved as a draft. Stock is not issued until an approved order is fulfilled.');
    }

    public function approve(Request $request, int $order)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.approve');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($order, $companyId, $branchId, $request) {
            $document = SalesOrder::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->with('lines')->lockForUpdate()->findOrFail($order);
            abort_unless($document->status === 'draft', 409, 'Only draft sales orders can be approved.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The sales order creator cannot approve their own order.');

            foreach ($document->lines as $line) {
                Product::where('company_id', $companyId)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                $onHand = InventoryLedger::onHand($companyId, (int) $document->warehouse_id, (int) $line->product_id);
                $reserved = (float) DB::table('sales_order_lines')
                    ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                    ->where('sales_orders.company_id', $companyId)
                    ->where('sales_orders.warehouse_id', $document->warehouse_id)
                    ->where('sales_order_lines.product_id', $line->product_id)
                    ->where('sales_orders.status', 'approved')
                    ->where('sales_orders.id', '!=', $document->id)
                    ->sum('sales_order_lines.quantity');
                $available = round($onHand - $reserved, 3);
                if ((float) $line->quantity > $available + 0.0005) {
                    throw ValidationException::withMessages(['stock' => "Insufficient unreserved stock for {$line->product?->name}. Available: {$available}."]);
                }
            }

            $document->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        });

        return back()->with('status', 'Sales order approved and stock reserved against competing approved orders.');
    }

    public function fulfill(Request $request, int $order)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.fulfill');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($order, $companyId, $branchId, $request) {
            $document = SalesOrder::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->with(['lines.product', 'customer'])->lockForUpdate()->findOrFail($order);
            abort_unless($document->status === 'approved', 409, 'Only approved sales orders can be fulfilled.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The sales order creator cannot fulfil their own order.');
            abort_if((int) $document->approved_by === (int) $request->user()->id, 403, 'The sales order approver cannot fulfil the same order.');

            $warehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($document->warehouse_id);
            $branch = $warehouse->branch_id;
            foreach ($document->lines as $line) {
                Product::where('company_id', $companyId)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                $onHand = InventoryLedger::onHand($companyId, (int) $warehouse->id, (int) $line->product_id);
                $otherReserved = (float) DB::table('sales_order_lines')
                    ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                    ->where('sales_orders.company_id', $companyId)
                    ->where('sales_orders.warehouse_id', $warehouse->id)
                    ->where('sales_order_lines.product_id', $line->product_id)
                    ->where('sales_orders.status', 'approved')
                    ->where('sales_orders.id', '!=', $document->id)
                    ->sum('sales_order_lines.quantity');
                if ((float) $line->quantity > round($onHand - $otherReserved, 3) + 0.0005) {
                    throw ValidationException::withMessages(['stock' => "Reserved stock for {$line->product?->name} is no longer available. Replenish or cancel another order first."]);
                }
            }

            $revenue = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => '4000'],
                ['name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]
            );
            abort_unless($revenue->account_type === 'income' && $revenue->normal_balance === 'credit', 422, 'Revenue control account 4000 must be a credit-normal income account.');

            $invoiceNumber = 'SO-INV-' . strtoupper(Str::random(12));
            $invoice = CustomerInvoice::create([
                'company_id' => $companyId, 'branch_id' => $branch,
                'customer_id' => $document->customer_id, 'sales_order_id' => $document->id,
                'revenue_account_id' => $revenue->id, 'invoice_number' => $invoiceNumber,
                'invoice_date' => now()->toDateString(), 'due_date' => $document->due_date,
                'amount' => $document->total_amount, 'net_amount' => $document->net_amount,
                'gst_treatment' => $document->gst_treatment, 'gst_rate' => $document->gst_rate,
                'gst_amount' => $document->gst_amount, 'currency' => 'PGK',
                'description' => 'Sales order ' . $document->order_number,
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);

            foreach ($document->lines as $line) {
                DB::table('customer_invoice_lines')->insert([
                    'customer_invoice_id' => $invoice->id, 'product_id' => $line->product_id,
                    'description' => $line->product?->name ?? 'Sales item',
                    'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                    'net_amount' => $line->net_amount, 'gst_rate' => $line->gst_rate,
                    'gst_amount' => $line->gst_amount, 'total_amount' => $line->total_amount,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                InventoryLedger::record(
                    $companyId, (int) $warehouse->id, (int) $line->product_id,
                    'issue', (float) $line->quantity, 0, $request->user()->id,
                    'sales_order', $document->id, 'Fulfilment of sales order ' . $document->order_number
                );
            }

            $document->update([
                'status' => 'fulfilled', 'customer_invoice_id' => $invoice->id,
                'fulfilled_by' => $request->user()->id, 'fulfilled_at' => now(),
            ]);
        });

        return back()->with('status', 'Sales order fulfilled. Stock has been issued and a draft customer invoice is ready for independent posting.');
    }

    public function cancel(Request $request, int $order)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($order, $companyId, $branchId, $request) {
            $document = SalesOrder::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($order);
            abort_unless(in_array($document->status, ['draft', 'approved'], true), 409, 'Only draft or approved sales orders can be cancelled.');
            $document->update(['status' => 'cancelled', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now()]);
        });
        return back()->with('status', 'Sales order cancelled; its reserved stock is released.');
    }
}
