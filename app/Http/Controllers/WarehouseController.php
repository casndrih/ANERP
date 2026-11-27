<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class WarehouseController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $warehouses=Warehouse::where('company_id',$companyId)->with('branch')->orderBy('name')->get();
        $branches=Branch::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        return view('inventory.warehouses.index',compact('warehouses','branches'));
    }
    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','inventory_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'code'=>['required','string','max:40',Rule::unique('warehouses')->where(fn($q)=>$q->where('company_id',$companyId))],
            'name'=>['required','string','max:160'],'location'=>['nullable','string','max:255'],
            'branch_id'=>['nullable','integer',Rule::exists('branches','id')->where('company_id',$companyId)],
        ]);
        $data['company_id']=$companyId;
        Warehouse::create($data);
        return back()->with('status','Warehouse created.');
    }
}
