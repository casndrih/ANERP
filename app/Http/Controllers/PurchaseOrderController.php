<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use App\Support\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class PurchaseOrderController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $orders=PurchaseOrder::where('company_id',$companyId)->with(['supplier','warehouse'])->latest('order_date')->paginate(25);
        $suppliers=Supplier::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $warehouses=Warehouse::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $products=Product::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        return view('procurement.orders.index',compact('orders','suppliers','warehouses','products'));
    }
    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','procurement_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'supplier_id'=>['required','integer',Rule::exists('suppliers','id')->where('company_id',$companyId)],
            'warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'order_date'=>['required','date'],'expected_date'=>['nullable','date','after_or_equal:order_date'],
            'notes'=>['nullable','string','max:2000'],
            'lines'=>['required','array','min:1'],
            'lines.*.product_id'=>['required','integer',Rule::exists('products','id')->where('company_id',$companyId)],
            'lines.*.quantity'=>['required','numeric','gt:0'],
            'lines.*.unit_cost'=>['required','numeric','min:0'],
        ]);
        DB::transaction(function() use($data,$companyId,$request) {
            $subtotal=collect($data['lines'])->sum(fn($line)=>(float)$line['quantity']*(float)$line['unit_cost']);
            $order=PurchaseOrder::create([
                'company_id'=>$companyId,'supplier_id'=>$data['supplier_id'],'warehouse_id'=>$data['warehouse_id'],
                'order_number'=>'PO-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
                'order_date'=>$data['order_date'],'expected_date'=>$data['expected_date']??null,
                'status'=>'draft','currency'=>'PGK','notes'=>$data['notes']??null,
                'subtotal'=>$subtotal,'tax_total'=>0,'total'=>$subtotal,'created_by'=>$request->user()->id,
            ]);
            foreach($data['lines'] as $line) {
                PurchaseOrderLine::create([
                    'purchase_order_id'=>$order->id,'product_id'=>$line['product_id'],
                    'quantity_ordered'=>$line['quantity'],'quantity_received'=>0,
                    'unit_cost'=>$line['unit_cost'],'line_total'=>(float)$line['quantity']*(float)$line['unit_cost'],
                ]);
            }
        });
        return back()->with('status','Draft purchase order created. Submit it for approval when ready.');
    }
    public function submit(Request $request, int $order) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $po=PurchaseOrder::where('company_id',$companyId)->findOrFail($order);
        abort_unless($po->status==='draft',409,'Only draft purchase orders can be submitted.');
        $po->update(['status'=>'submitted']);
        return back()->with('status','Purchase order submitted for approval.');
    }
    public function approve(Request $request, int $order) {
        CompanyAuthorizer::requireRole($request,['owner','admin','procurement_approver']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $po=PurchaseOrder::where('company_id',$companyId)->findOrFail($order);
        abort_unless($po->status==='submitted',409,'Only submitted purchase orders can be approved.');
        $po->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now()]);
        return back()->with('status','Purchase order approved.');
    }
    public function receive(Request $request, int $order, int $line) {
        CompanyAuthorizer::requireRole($request,['owner','admin','inventory_manager','procurement_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate(['quantity'=>['required','numeric','gt:0']]);
        DB::transaction(function() use($request,$order,$line,$companyId,$data) {
            $po=PurchaseOrder::where('company_id',$companyId)->lockForUpdate()->findOrFail($order);
            if(!in_array($po->status,['approved','partially_received'],true)) throw ValidationException::withMessages(['quantity'=>'This purchase order is not approved for receiving.']);
            $poLine=PurchaseOrderLine::where('purchase_order_id',$po->id)->lockForUpdate()->findOrFail($line);
            $remaining=(float)$poLine->quantity_ordered-(float)$poLine->quantity_received;
            if((float)$data['quantity']>$remaining) throw ValidationException::withMessages(['quantity'=>"Cannot receive more than the remaining quantity ({$remaining})."]);
            $product=Product::where('company_id',$companyId)->whereKey($poLine->product_id)->lockForUpdate()->firstOrFail();
            if($product->track_stock) {
                InventoryLedger::record($companyId,(int)$po->warehouse_id,(int)$product->id,'purchase_receipt',(float)$data['quantity'],(float)$poLine->unit_cost,$request->user()->id,'purchase_order',$po->id);
            }
            $poLine->increment('quantity_received',(float)$data['quantity']);
            $po->load('lines');
            $allReceived=$po->lines->every(fn($l)=>(float)$l->quantity_received >= (float)$l->quantity_ordered);
            $po->update(['status'=>$allReceived?'received':'partially_received']);
        });
        return back()->with('status','Goods receipt posted to the stock ledger.');
    }
}
