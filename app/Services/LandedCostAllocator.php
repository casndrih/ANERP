<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandedCostAllocator
{
    /**
     * Capitalise the portion of freight/insurance/import charges still represented
     * by on-hand stock. The consumed portion is expensed to COGS.
     *
     * @return array{inventory: float, cogs: float, allocations: array<int, array<string, mixed>>}
     */
    public function allocate(int $companyId, int $billId, PurchaseOrder $order, float $amount): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Landed cost must be greater than zero.']);
        }

        $lines = $order->lines()->with('product')->lockForUpdate()->get()
            ->filter(fn ($line) => (float) $line->quantity_received > 0 && $line->product?->track_stock);

        $basisTotal = $lines->sum(fn ($line) => (float) $line->quantity_received * (float) $line->unit_cost);
        if ($lines->isEmpty() || $basisTotal <= 0) {
            throw ValidationException::withMessages(['purchase_order_id' => 'The selected purchase order has no received stock to allocate landed costs against.']);
        }

        $remainingAmount = round($amount, 2);
        $inventoryTotal = 0.0;
        $cogsTotal = 0.0;
        $allocations = [];
        $lastIndex = $lines->count() - 1;

        foreach ($lines->values() as $index => $line) {
            $basis = (float) $line->quantity_received * (float) $line->unit_cost;
            $allocated = $index === $lastIndex
                ? $remainingAmount
                : round($amount * ($basis / $basisTotal), 2);
            $remainingAmount = round($remainingAmount - $allocated, 2);

            $balance = DB::table('inventory_cost_balances')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $order->warehouse_id)
                ->where('product_id', $line->product_id)
                ->lockForUpdate()
                ->first();

            $receivedQty = (float) $line->quantity_received;
            $onHandQty = max(0, (float) ($balance->quantity_on_hand ?? 0));
            $stockRatio = min(1, $receivedQty > 0 ? $onHandQty / $receivedQty : 0);
            $inventoryShare = round($allocated * $stockRatio, 2);
            $cogsShare = round($allocated - $inventoryShare, 2);

            if ($inventoryShare > 0 && $balance) {
                $newValue = round((float) $balance->inventory_value + $inventoryShare, 2);
                $newAverage = $onHandQty > 0 ? round($newValue / $onHandQty, 4) : 0;
                DB::table('inventory_cost_balances')->where('id', $balance->id)->update([
                    'inventory_value' => $newValue,
                    'average_unit_cost' => $newAverage,
                    'updated_at' => now(),
                ]);

                $layers = DB::table('inventory_cost_layers')
                    ->where('company_id', $companyId)
                    ->where('warehouse_id', $order->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($layers->isNotEmpty()) {
                    $qty = $layers->sum(fn ($layer) => (float) $layer->quantity_remaining);
                    if ($qty > 0) {
                        $perUnitIncrease = $inventoryShare / $qty;
                        foreach ($layers as $layer) {
                            DB::table('inventory_cost_layers')->where('id', $layer->id)->update([
                                'unit_cost' => round((float) $layer->unit_cost + $perUnitIncrease, 4),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            $allocations[] = [
                'company_id' => $companyId,
                'supplier_bill_id' => $billId,
                'purchase_order_id' => $order->id,
                'purchase_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'warehouse_id' => $order->warehouse_id,
                'allocated_amount' => $allocated,
                'inventory_amount' => $inventoryShare,
                'cogs_amount' => $cogsShare,
            ];
            $inventoryTotal += $inventoryShare;
            $cogsTotal += $cogsShare;
        }

        foreach ($allocations as $allocation) {
            DB::table('landed_cost_allocations')->insert($allocation + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'inventory' => round($inventoryTotal, 2),
            'cogs' => round($cogsTotal, 2),
            'allocations' => $allocations,
        ];
    }
}
