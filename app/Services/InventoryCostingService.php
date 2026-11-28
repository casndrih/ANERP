<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCostingService
{
    public function apply(StockMovement $movement): void
    {
        $method = DB::table('inventory_valuation_settings')
            ->where('company_id', $movement->company_id)
            ->value('valuation_method') ?? 'weighted_average';

        if (!in_array($method, ['fifo', 'weighted_average'], true)) {
            throw ValidationException::withMessages(['inventory' => 'The company inventory valuation method is invalid.']);
        }

        if ($movement->movement_type === 'transfer_in' && $movement->reference_type === 'stock_transfer' && $movement->reference_id) {
            $outbound = StockMovement::where('company_id', $movement->company_id)
                ->where('reference_type', 'stock_transfer')
                ->where('reference_id', $movement->reference_id)
                ->where('movement_type', 'transfer_out')
                ->first();
            if ($outbound) {
                $movement->unit_cost = $outbound->unit_cost;
                $movement->save();
            }
        }

        $balance = DB::table('inventory_cost_balances')
            ->where('company_id', $movement->company_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('product_id', $movement->product_id)
            ->lockForUpdate()
            ->first();

        if (!$balance) {
            $id = DB::table('inventory_cost_balances')->insertGetId([
                'company_id' => $movement->company_id,
                'warehouse_id' => $movement->warehouse_id,
                'product_id' => $movement->product_id,
                'quantity_on_hand' => 0,
                'inventory_value' => 0,
                'average_unit_cost' => 0,
                'is_initialized' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = DB::table('inventory_cost_balances')->where('id', $id)->lockForUpdate()->first();
        }

        if (!$balance->is_initialized) {
            $this->rebuildLegacyBalance($movement, $method);
            $balance = DB::table('inventory_cost_balances')->where('id', $balance->id)->lockForUpdate()->first();
        }

        $quantity = (float) $movement->quantity;
        $currentQty = (float) $balance->quantity_on_hand;
        $currentValue = (float) $balance->inventory_value;

        if ($this->isInbound($movement->movement_type)) {
            $unitCost = (float) $movement->unit_cost;
            $newQty = round($currentQty + $quantity, 3);
            $newValue = round($currentValue + ($quantity * $unitCost), 2);
            if ($method === 'fifo') {
                DB::table('inventory_cost_layers')->insert([
                    'company_id' => $movement->company_id,
                    'warehouse_id' => $movement->warehouse_id,
                    'product_id' => $movement->product_id,
                    'source_movement_id' => $movement->id,
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'unit_cost' => $unitCost,
                    'received_at' => $movement->occurred_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($this->isOutbound($movement->movement_type)) {
            if ($quantity > $currentQty + 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => 'The valuation ledger has insufficient costed stock. Reconcile opening stock and historical movements before issuing inventory.',
                ]);
            }

            if ($method === 'fifo') {
                $unitCost = $this->consumeFifo($movement, $quantity);
            } else {
                if ($currentQty <= 0) {
                    throw ValidationException::withMessages(['quantity' => 'No valued stock is available for this issue.']);
                }
                $unitCost = round($currentValue / $currentQty, 2);
            }

            $movement->unit_cost = $unitCost;
            $movement->save();
            $newQty = round(max(0, $currentQty - $quantity), 3);
            $newValue = round(max(0, $currentValue - ($quantity * $unitCost)), 2);
        } else {
            return;
        }

        DB::table('inventory_cost_balances')->where('id', $balance->id)->update([
            'quantity_on_hand' => $newQty,
            'inventory_value' => $newValue,
            'average_unit_cost' => $newQty > 0 ? round($newValue / $newQty, 4) : 0,
            'is_initialized' => true,
            'updated_at' => now(),
        ]);
    }

    private function rebuildLegacyBalance(StockMovement $current, string $method): void
    {
        $history = StockMovement::where('company_id', $current->company_id)
            ->where('warehouse_id', $current->warehouse_id)
            ->where('product_id', $current->product_id)
            ->where(function ($query) use ($current) {
                $query->where('occurred_at', '<', $current->occurred_at)
                    ->orWhere(function ($sameTime) use ($current) {
                        $sameTime->where('occurred_at', $current->occurred_at)->where('id', '<', $current->id);
                    });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $qty = 0.0;
        $value = 0.0;
        foreach ($history as $old) {
            $movementQty = (float) $old->quantity;
            $unitCost = (float) $old->unit_cost;
            if ($this->isInbound($old->movement_type)) {
                $qty += $movementQty;
                $value += $movementQty * $unitCost;
                if ($method === 'fifo') {
                    DB::table('inventory_cost_layers')->updateOrInsert(
                        ['source_movement_id' => $old->id],
                        [
                            'company_id' => $old->company_id,
                            'warehouse_id' => $old->warehouse_id,
                            'product_id' => $old->product_id,
                            'quantity_received' => $movementQty,
                            'quantity_remaining' => $movementQty,
                            'unit_cost' => $unitCost,
                            'received_at' => $old->occurred_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            } elseif ($this->isOutbound($old->movement_type)) {
                if ($movementQty > $qty + 0.0005) {
                    throw ValidationException::withMessages([
                        'inventory' => 'Historical stock movements contain an uncosted or negative balance. Complete a stock valuation reconciliation before enabling automatic costing.',
                    ]);
                }
                if ($method === 'fifo') {
                    $this->consumeFifo($old, $movementQty);
                    $value = max(0, $value - ($movementQty * $unitCost));
                } else {
                    $average = $qty > 0 ? $value / $qty : 0;
                    $value = max(0, $value - ($movementQty * $average));
                }
                $qty = max(0, $qty - $movementQty);
            }
        }

        DB::table('inventory_cost_balances')
            ->where('company_id', $current->company_id)
            ->where('warehouse_id', $current->warehouse_id)
            ->where('product_id', $current->product_id)
            ->update([
                'quantity_on_hand' => round($qty, 3),
                'inventory_value' => round($value, 2),
                'average_unit_cost' => $qty > 0 ? round($value / $qty, 4) : 0,
                'is_initialized' => true,
                'updated_at' => now(),
            ]);
    }

    private function consumeFifo(StockMovement $movement, float $quantity): float
    {
        $layers = DB::table('inventory_cost_layers')
            ->where('company_id', $movement->company_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('product_id', $movement->product_id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $cost = 0.0;
        foreach ($layers as $layer) {
            if ($remaining <= 0.0005) {
                break;
            }
            $take = min($remaining, (float) $layer->quantity_remaining);
            $cost += $take * (float) $layer->unit_cost;
            $remaining -= $take;
            DB::table('inventory_cost_layers')->where('id', $layer->id)->update([
                'quantity_remaining' => round((float) $layer->quantity_remaining - $take, 3),
                'updated_at' => now(),
            ]);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages([
                'quantity' => 'FIFO cost layers do not cover the requested quantity. Reconcile opening stock before continuing.',
            ]);
        }

        return round($cost / $quantity, 2);
    }

    private function isInbound(string $type): bool
    {
        return in_array($type, ['opening', 'purchase_receipt', 'transfer_in', 'adjustment_in'], true);
    }

    private function isOutbound(string $type): bool
    {
        return in_array($type, ['issue', 'transfer_out', 'adjustment_out'], true);
    }
}
