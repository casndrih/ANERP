<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use App\Support\CompanyPermissionMap;
use App\Support\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'inventory.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $warehouses = Warehouse::where('company_id', $companyId)->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')->get();
        $products = Product::where('company_id', $companyId)->where('is_active', true)->where('track_stock', true)
            ->orderBy('name')->get();
        $membership = $request->user()->companies()->where('companies.id', $companyId)->wherePivot('is_active', true)->first();
        $role = $membership?->pivot?->role_code ?? '';
        $canManage = CompanyPermissionMap::allows($role, 'inventory.manage');
        $canApprove = CompanyPermissionMap::allows($role, 'inventory.adjust.approve');
        $counts = StockCount::where('company_id', $companyId)
            ->when($branchId, fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $branchId)))
            ->with(['warehouse', 'lines.product'])->latest('count_date')->paginate(20);

        return view('inventory.counts.index', compact('warehouses', 'products', 'counts', 'canManage', 'canApprove'));
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'inventory.manage');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($warehouse) => $warehouse->where('branch_id', $branchId)))],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)->where('is_active', true)->where('track_stock', true))],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $companyId, $request) {
            $warehouse = Warehouse::where('company_id', $companyId)->lockForUpdate()->findOrFail($data['warehouse_id']);
            $count = StockCount::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouse->id,
                'count_number' => 'SC-' . now()->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
                'count_date' => $data['count_date'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::where('company_id', $companyId)->findOrFail($line['product_id']);
                $systemQuantity = InventoryLedger::onHand($companyId, (int) $warehouse->id, (int) $product->id);
                $averageCost = DB::table('inventory_cost_balances')
                    ->where('company_id', $companyId)->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)->value('average_unit_cost');
                $unitCost = (float) ($averageCost ?? $product->standard_cost);
                $counted = (float) $line['counted_quantity'];
                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'product_id' => $product->id,
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $counted,
                    'variance_quantity' => round($counted - $systemQuantity, 3),
                    'unit_cost' => $unitCost,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });

        return back()->with('status', 'Draft stock count created. Review variances and submit it for independent approval.');
    }

    public function submit(Request $request, int $count)
    {
        CompanyAuthorizer::requirePermission($request, 'inventory.manage');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $stockCount = StockCount::where('company_id', $companyId)
            ->when($branchId, fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $branchId)))
            ->withCount('lines')->findOrFail($count);
        abort_unless($stockCount->status === 'draft' && $stockCount->lines_count > 0, 409, 'Only non-empty draft stock counts can be submitted.');
        $stockCount->update(['status' => 'submitted', 'submitted_by' => $request->user()->id]);

        return back()->with('status', 'Stock count submitted for independent approval.');
    }

    public function approve(Request $request, int $count)
    {
        CompanyAuthorizer::requirePermission($request, 'inventory.adjust.approve');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($request, $companyId, $branchId, $count) {
            $stockCount = StockCount::where('company_id', $companyId)
                ->when($branchId, fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $branchId)))
                ->with(['lines', 'warehouse'])->lockForUpdate()->findOrFail($count);
            abort_unless($stockCount->status === 'submitted', 409, 'Only submitted stock counts can be approved.');
            abort_if((int) $stockCount->created_by === (int) $request->user()->id || (int) $stockCount->submitted_by === (int) $request->user()->id, 403, 'The stock count creator or submitter cannot approve their own count.');

            foreach ($stockCount->lines as $line) {
                $currentQuantity = InventoryLedger::onHand($companyId, (int) $stockCount->warehouse_id, (int) $line->product_id);
                if (abs($currentQuantity - (float) $line->system_quantity) > 0.0005) {
                    throw ValidationException::withMessages([
                        'count' => 'Stock changed after this count was captured. Recount the affected warehouse before approval.',
                    ]);
                }

                $variance = (float) $line->variance_quantity;
                if (abs($variance) <= 0.0005) {
                    continue;
                }

                InventoryLedger::record(
                    $companyId,
                    (int) $stockCount->warehouse_id,
                    (int) $line->product_id,
                    $variance > 0 ? 'adjustment_in' : 'adjustment_out',
                    abs($variance),
                    (float) $line->unit_cost,
                    (int) $request->user()->id,
                    'stock_count',
                    $stockCount->id,
                    $line->notes ?: $stockCount->notes
                );
            }

            $stockCount->update([
                'status' => 'posted',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'posted_at' => now(),
            ]);
        });

        return back()->with('status', 'Stock count approved. Variance adjustments were posted to inventory and the general ledger.');
    }
}
