<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class WarehouseController extends Controller {
    public function index(Request $request) {
        CompanyAuthorizer::requirePermission($request,'inventory.view');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $branchId=CompanyAuthorizer::activeBranchId($request);
        $warehouses=Warehouse::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->with('branch')->orderBy('name')->get();
        $branches=Branch::where('company_id',$companyId)->where('is_active',true)->when($branchId,fn($q)=>$q->whereKey($branchId))->orderBy('name')->get();
        return view('inventory.warehouses.index',compact('warehouses','branches'));
    }
    public function store(Request $request) {
        CompanyAuthorizer::requirePermission($request,'inventory.manage');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $branchId=CompanyAuthorizer::activeBranchId($request);
        $data=$request->validate([
            'code'=>['required','string','max:40',Rule::unique('warehouses')->where(fn($q)=>$q->where('company_id',$companyId))],
            'name'=>['required','string','max:160'],'location'=>['nullable','string','max:255'],
            'branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true)->when($branchId,fn($b)=>$b->whereKey($branchId)))],
        ]);
        if($branchId!==null) $data['branch_id']=$branchId;
        $data['company_id']=$companyId;
        Warehouse::create($data);
        return back()->with('status','Warehouse created.');
    }
}
