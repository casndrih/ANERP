<?php
namespace App\Support;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
class InventoryLedger {
    public static function onHand(int $companyId, int $warehouseId, int $productId, bool $lock = false): float {
        $query = StockMovement::query()
            ->where('company_id',$companyId)->where('warehouse_id',$warehouseId)->where('product_id',$productId);
        if ($lock) $query->lockForUpdate();
        $in = ['opening','purchase_receipt','transfer_in','adjustment_in'];
        $out = ['issue','transfer_out','adjustment_out'];
        return (float) $query->selectRaw(
            'COALESCE(SUM(CASE WHEN movement_type IN (?,?,?,?) THEN quantity WHEN movement_type IN (?,?,?) THEN -quantity ELSE 0 END),0) AS balance',
            [...$in,...$out]
        )->value('balance');
    }

    public static function record(int $companyId, int $warehouseId, int $productId, string $type, float $quantity, float $cost, ?int $userId, ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null): StockMovement {
        return DB::transaction(function () use ($companyId,$warehouseId,$productId,$type,$quantity,$cost,$userId,$referenceType,$referenceId,$notes) {
            $movement = StockMovement::create([
                'company_id'=>$companyId,'warehouse_id'=>$warehouseId,'product_id'=>$productId,
                'movement_type'=>$type,'quantity'=>$quantity,'unit_cost'=>$cost,
                'reference_type'=>$referenceType,'reference_id'=>$referenceId,
                'occurred_at'=>now(),'created_by'=>$userId,'notes'=>$notes,
            ]);
            app(\App\Services\InventoryCostingService::class)->apply($movement);
            app(\App\Services\InventoryLedgerPoster::class)->post($movement->fresh());
            return $movement->fresh();
        });
    }
}
