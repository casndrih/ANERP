<?php
namespace App\Http\Controllers;
use App\Models\Supplier;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class SupplierController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $suppliers=Supplier::where('company_id',$companyId)->orderBy('name')->paginate(25);
        return view('procurement.suppliers.index',compact('suppliers'));
    }
    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','procurement_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'code'=>['required','string','max:40',Rule::unique('suppliers')->where(fn($q)=>$q->where('company_id',$companyId))],
            'name'=>['required','string','max:180'],'tin'=>['nullable','string','max:80'],
            'email'=>['nullable','email','max:180'],'phone'=>['nullable','string','max:50'],
            'address'=>['nullable','string','max:2000'],
        ]);
        $data['company_id']=$companyId;
        Supplier::create($data);
        return back()->with('status','Supplier created.');
    }
}
