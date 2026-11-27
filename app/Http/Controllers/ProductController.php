<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class ProductController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $products=Product::where('company_id',$companyId)->orderBy('name')->paginate(25);
        return view('inventory.products.index',['products'=>$products,'company'=>$request->attributes->get('activeCompany')]);
    }
    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','inventory_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'sku'=>['required','string','max:80',Rule::unique('products')->where(fn($q)=>$q->where('company_id',$companyId))],
            'name'=>['required','string','max:255'],
            'barcode'=>['nullable','string','max:100'],
            'unit'=>['required','string','max:30'],
            'standard_cost'=>['required','numeric','min:0'],
            'selling_price'=>['required','numeric','min:0'],
            'track_stock'=>['sometimes','boolean'],
        ]);
        $data['company_id']=$companyId;
        $data['track_stock']=$request->boolean('track_stock');
        Product::create($data);
        return redirect()->route('inventory.products.index')->with('status','Product created.');
    }
}
