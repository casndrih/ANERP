<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use App\Support\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $warehouses=Warehouse::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $products=Product::where('company_id',$companyId)->where('is_active',true)->where('track_stock',true)->orderBy('name')->get();
        $movements=StockMovement::where('company_id',$companyId)->with(['product','warehouse'])->latest('occurred_at')->paginate(30);
        $balances=StockMovement::where('stock_movements.company_id',$companyId)
            ->join('products','products.id','=','stock_movements.product_id')
            ->join('warehouses','warehouses.id','=','stock_movements.warehouse_id')
            ->selectRaw("stock_movements.product_id, stock_movements.warehouse_id, products.name as product_name, products.sku, warehouses.name as warehouse_name, SUM(CASE WHEN movement_type IN ('opening','purchase_receipt','transfer_in','adjustment_in') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out') THEN -quantity ELSE 0 END) as on_hand")
            ->groupBy('stock_movements.product_id','stock_movements.warehouse_id','products.name','products.sku','warehouses.name')
            ->orderBy('products.name')->get();
        return view('inventory.movements.index',compact('warehouses','products','movements','balances'));
    }

    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','inventory_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'movement_type'=>['required',Rule::in(['opening','issue','adjustment_in','adjustment_out'])],
            'warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],
            'product_id'=>['required','integer',Rule::exists('products','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],
            'quantity'=>['required','numeric','gt:0'],
            'unit_cost'=>['required','numeric','min:0'],
            'notes'=>['nullable','string','max:500'],
        ]);
        DB::transaction(function() use($data,$companyId,$request) {
            $product=Product::where('company_id',$companyId)->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            if(!$product->track_stock) throw ValidationException::withMessages(['product_id'=>'Stock tracking is disabled for this product.']);
            if(in_array($data['movement_type'],['issue','adjustment_out'],true)) {
                $onHand=InventoryLedger::onHand($companyId,(int)$data['warehouse_id'],(int)$product->id);
                if((float)$data['quantity']>$onHand) throw ValidationException::withMessages(['quantity'=>"Insufficient stock. Available: {$onHand}."]);
            }
            InventoryLedger::record($companyId,(int)$data['warehouse_id'],(int)$product->id,$data['movement_type'],(float)$data['quantity'],(float)$data['unit_cost'],$request->user()->id,'manual',null,$data['notes']??null);
        });
        return back()->with('status','Stock movement recorded in the inventory ledger.');
    }

    public function transfer(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','inventory_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'from_warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'to_warehouse_id'=>['required','different:from_warehouse_id','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'product_id'=>['required','integer',Rule::exists('products','id')->where('company_id',$companyId)],
            'quantity'=>['required','numeric','gt:0'],
            'unit_cost'=>['required','numeric','min:0'],
            'notes'=>['nullable','string','max:500'],
        ]);
        DB::transaction(function() use($data,$companyId,$request) {
            $product=Product::where('company_id',$companyId)->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            if(!$product->track_stock) throw ValidationException::withMessages(['product_id'=>'Stock tracking is disabled for this product.']);
            $available=InventoryLedger::onHand($companyId,(int)$data['from_warehouse_id'],(int)$product->id);
            if((float)$data['quantity']>$available) throw ValidationException::withMessages(['quantity'=>"Insufficient stock. Available: {$available}."]);
            $transfer=StockTransfer::create([
                'company_id'=>$companyId,
                'transfer_number'=>'TRF-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
                'from_warehouse_id'=>$data['from_warehouse_id'],
                'to_warehouse_id'=>$data['to_warehouse_id'],
                'status'=>'posted',
                'notes'=>$data['notes']??null,
                'created_by'=>$request->user()->id,
            ]);
            InventoryLedger::record($companyId,(int)$data['from_warehouse_id'],(int)$product->id,'transfer_out',(float)$data['quantity'],(float)$data['unit_cost'],$request->user()->id,'stock_transfer',$transfer->id,$transfer->notes);
            InventoryLedger::record($companyId,(int)$data['to_warehouse_id'],(int)$product->id,'transfer_in',(float)$data['quantity'],(float)$data['unit_cost'],$request->user()->id,'stock_transfer',$transfer->id,$transfer->notes);
        });
        return back()->with('status','Warehouse transfer posted.');
    }
}
