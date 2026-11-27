<?php
namespace App\Http\Controllers;
use App\Models\Product;
use Illuminate\Http\Request;
class ProductController extends Controller {
    public function index(Request $request) {
        $company = $request->attributes->get('activeCompany');
        $products = Product::where('company_id',$company->id)->orderBy('name')->paginate(25);
        return view('inventory.products.index',compact('products','company'));
    }
    public function store(Request $request) {
        $company = $request->attributes->get('activeCompany');
        $data = $request->validate([
            'sku'=>['required','string','max:80'],
            'name'=>['required','string','max:255'],
            'barcode'=>['nullable','string','max:100'],
            'unit'=>['required','string','max:30'],
            'standard_cost'=>['required','numeric','min:0'],
            'selling_price'=>['required','numeric','min:0'],
            'track_stock'=>['sometimes','boolean'],
        ]);
        $data['company_id']=$company->id;
        $data['track_stock']=$request->boolean('track_stock');
        Product::create($data);
        return redirect()->route('inventory.products.index')->with('status','Product created.');
    }
}
