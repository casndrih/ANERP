<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryLedgerPoster
{
    public function post(StockMovement $movement): void
    {
        DB::transaction(function () use ($movement) {
            $movement = StockMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if (DB::table('stock_movement_journals')->where('stock_movement_id', $movement->id)->exists()) {
                return;
            }

            $warehouse = Warehouse::where('company_id', $movement->company_id)->findOrFail($movement->warehouse_id);
            $branchId = $warehouse->branch_id;
            $value = round((float) ($movement->cost_value ?? ((float) $movement->quantity * (float) $movement->unit_cost)), 2);
            if ($value <= 0) {
                return;
            }

            if (in_array($movement->movement_type, ['transfer_out', 'transfer_in'], true)) {
                $this->postTransferPair($movement, $value);
                return;
            }

            $accounts = $this->accounts((int) $movement->company_id);
            $inventory = $accounts['inventory']->id;
            $lines = match ($movement->movement_type) {
                'opening' => [[$inventory, $value, 0], [$accounts['opening_equity']->id, 0, $value]],
                'purchase_receipt' => [[$inventory, $value, 0], [$accounts['goods_received_not_invoiced']->id, 0, $value]],
                'issue' => [[$accounts['cost_of_goods_sold']->id, $value, 0], [$inventory, 0, $value]],
                'adjustment_in' => [[$inventory, $value, 0], [$accounts['inventory_adjustment_gain']->id, 0, $value]],
                'adjustment_out' => [[$accounts['inventory_shrinkage']->id, $value, 0], [$inventory, 0, $value]],
                default => null,
            };

            if ($lines === null) {
                return;
            }

            $this->createJournal($movement, $branchId, $lines, 'inventory_movement', 'Inventory ' . $movement->movement_type . ' #' . $movement->id);
        });
    }

    private function postTransferPair(StockMovement $movement, float $value): void
    {
        if (!$movement->reference_type || !$movement->reference_id) {
            throw ValidationException::withMessages(['inventory' => 'Stock transfer movement is missing its transfer reference.']);
        }

        $pair = StockMovement::where('company_id', $movement->company_id)
            ->where('reference_type', $movement->reference_type)
            ->where('reference_id', $movement->reference_id)
            ->whereIn('movement_type', ['transfer_out', 'transfer_in'])
            ->with('warehouse')
            ->lockForUpdate()
            ->get();

        if ($pair->count() < 2) {
            return;
        }
        $out = $pair->firstWhere('movement_type', 'transfer_out');
        $in = $pair->firstWhere('movement_type', 'transfer_in');
        if (!$out || !$in) {
            throw ValidationException::withMessages(['inventory' => 'Transfer must contain one outbound and one inbound movement.']);
        }

        $outValue = round((float) ($out->cost_value ?? ((float) $out->quantity * (float) $out->unit_cost)), 2);
        $inValue = round((float) ($in->cost_value ?? ((float) $in->quantity * (float) $in->unit_cost)), 2);
        if (abs($outValue - $inValue) > 0.009) {
            throw ValidationException::withMessages(['inventory' => 'Transfer valuation differs between source and destination movements.']);
        }

        $sourceBranch = $out->warehouse?->branch_id;
        $destinationBranch = $in->warehouse?->branch_id;
        if ($sourceBranch === $destinationBranch || $outValue <= 0) {
            return;
        }

        $accounts = $this->accounts((int) $movement->company_id);
        $inventoryId = $accounts['inventory']->id;
        $clearingId = $accounts['interbranch_clearing']->id;

        $this->createJournal($out, $sourceBranch, [
            [$clearingId, $outValue, 0],
            [$inventoryId, 0, $outValue],
        ], 'stock_transfer', 'Inter-branch stock transfer out #' . $movement->reference_id);

        $this->createJournal($in, $destinationBranch, [
            [$inventoryId, $inValue, 0],
            [$clearingId, 0, $inValue],
        ], 'stock_transfer', 'Inter-branch stock transfer in #' . $movement->reference_id);
    }

    private function createJournal(StockMovement $movement, ?int $branchId, array $lines, string $source, string $description): void
    {
        if (DB::table('stock_movement_journals')->where('stock_movement_id', $movement->id)->exists()) {
            return;
        }

        $debit = round(array_sum(array_column($lines, 1)), 2);
        $credit = round(array_sum(array_column($lines, 2)), 2);
        if ($debit <= 0 || abs($debit - $credit) > 0.009) {
            throw ValidationException::withMessages(['inventory' => 'Generated inventory journal is not balanced.']);
        }

        $entry = JournalEntry::create([
            'company_id' => $movement->company_id,
            'branch_id' => $branchId,
            'journal_number' => 'INV-GL-' . $movement->id,
            'journal_date' => $movement->occurred_at,
            'source' => $source,
            'status' => 'posted',
            'description' => $description,
            'created_by' => $movement->created_by,
            'posted_by' => $movement->created_by,
            'posted_at' => now(),
        ]);

        foreach ($lines as [$accountId, $dr, $cr]) {
            if ($dr == 0.0 && $cr == 0.0) {
                continue;
            }
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $accountId,
                'description' => $description,
                'debit' => $dr,
                'credit' => $cr,
            ]);
        }

        DB::table('stock_movement_journals')->insert([
            'stock_movement_id' => $movement->id,
            'journal_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function accounts(int $companyId): array
    {
        $definitions = [
            'inventory' => ['1200', 'Inventory', 'asset', 'debit'],
            'opening_equity' => ['3000', 'Owner equity', 'equity', 'credit'],
            'cost_of_goods_sold' => ['5000', 'Cost of goods sold', 'expense', 'debit'],
            'goods_received_not_invoiced' => ['2600', 'Goods received not invoiced', 'liability', 'credit'],
            'inventory_adjustment_gain' => ['4200', 'Inventory adjustment gains', 'income', 'credit'],
            'inventory_shrinkage' => ['6500', 'Inventory shrinkage expense', 'expense', 'debit'],
            'interbranch_clearing' => ['2700', 'Inter-branch inventory clearing', 'liability', 'credit'],
        ];

        $accounts = [];
        foreach ($definitions as $key => [$code, $name, $type, $normal]) {
            $accounts[$key] = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => $code],
                ['name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_control_account' => true, 'is_active' => true]
            );
            if ($accounts[$key]->account_type !== $type || $accounts[$key]->normal_balance !== $normal) {
                throw ValidationException::withMessages([
                    'inventory' => "Chart account {$code} has an incompatible account type or normal balance. Correct the chart before posting inventory.",
                ]);
            }
        }

        return $accounts;
    }
}
