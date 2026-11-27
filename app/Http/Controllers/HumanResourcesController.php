<?php
namespace App\Http\Controllers;
use App\Models\AttendanceEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class HumanResourcesController extends Controller {
    public function index(Request $request) {
        CompanyAuthorizer::requirePermission($request,'hr.view');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        return view('hr.index',[
            'employees'=>Employee::where('company_id',$companyId)->orderBy('last_name')->paginate(20),
            'leaveTypes'=>LeaveType::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get(),
            'leaveApplications'=>LeaveApplication::with(['employee','leaveType'])->where('company_id',$companyId)->latest()->limit(30)->get(),
            'attendance'=>AttendanceEntry::with('employee')->where('company_id',$companyId)->whereDate('work_date',today())->latest()->get(),
            'company'=>Company::findOrFail($companyId),
        ]);
    }
    public function storeEmployee(Request $request) {
        CompanyAuthorizer::requirePermission($request,'hr.manage');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'employee_number'=>['required','string','max:50',Rule::unique('employees')->where(fn($q)=>$q->where('company_id',$companyId))],
            'first_name'=>'required|string|max:100','last_name'=>'required|string|max:100',
            'email'=>'nullable|email|max:255','phone'=>'nullable|string|max:50',
            'branch_id'=>['nullable','integer',Rule::exists('branches','id')->where('company_id',$companyId)],
            'department'=>'nullable|string|max:120','job_title'=>'nullable|string|max:120',
            'employment_type'=>'nullable|string|max:40','hire_date'=>'nullable|date',
            'base_salary'=>'required|numeric|min:0','pay_frequency'=>'required|in:weekly,fortnightly,monthly',
            'tax_residency'=>'required|in:resident,non_resident','employment_declaration_received'=>'sometimes|boolean',
            'annual_dependant_tax_credit'=>'nullable|numeric|min:0|max:1050','superannuation_opt_in'=>'sometimes|boolean',
            'superannuation_exempt'=>'sometimes|boolean','superannuation_exempt_reason'=>'nullable|string|max:255',
            'superannuation_fund'=>'nullable|string|max:120','superannuation_member_number'=>'nullable|string|max:100',
        ]);
        $data['company_id']=$companyId; $data['currency']='PGK'; $data['status']='active';
        $data['employment_declaration_received']=$request->boolean('employment_declaration_received');
        $data['annual_dependant_tax_credit']=$data['annual_dependant_tax_credit']??0;
        $data['superannuation_opt_in']=$request->boolean('superannuation_opt_in');
        $data['superannuation_exempt']=$request->boolean('superannuation_exempt');
        Employee::create($data);
        return back()->with('status','Employee record created.');
    }
    public function setVoluntarySuperParticipation(Request $request) {
        CompanyAuthorizer::requirePermission($request,'company.settings.manage');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        Company::whereKey($companyId)->update(['superannuation_voluntary_participant'=>$request->boolean('superannuation_voluntary_participant')]);
        return back()->with('status','Company voluntary superannuation participation setting updated.');
    }
    public function storeLeaveType(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','hr_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'code'=>['required','string','max:30',Rule::unique('leave_types')->where(fn($q)=>$q->where('company_id',$companyId))],
            'name'=>'required|string|max:100','annual_entitlement'=>'required|numeric|min:0|max:366',
            'is_paid'=>'sometimes|boolean',
        ]);
        $data['company_id']=$companyId; $data['is_paid']=$request->boolean('is_paid');
        LeaveType::create($data);
        return back()->with('status','Leave type created.');
    }
    public function storeLeave(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','hr_manager','employee']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'employee_id'=>['required','integer',Rule::exists('employees','id')->where('company_id',$companyId)],
            'leave_type_id'=>['required','integer',Rule::exists('leave_types','id')->where('company_id',$companyId)],
            'start_date'=>'required|date','end_date'=>'required|date|after_or_equal:start_date',
            'days'=>'required|numeric|min:0.5|max:366','reason'=>'nullable|string|max:2000',
        ]);
        $data['company_id']=$companyId; $data['created_by']=$request->user()->id;
        LeaveApplication::create($data);
        return back()->with('status','Leave application submitted for review.');
    }
    public function decideLeave(Request $request, LeaveApplication $leave) {
        CompanyAuthorizer::requirePermission($request,'hr.manage');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        abort_unless($leave->company_id===$companyId,404);
        abort_unless($leave->status==='pending',422,'Only pending leave applications can be decided.');
        abort_if($leave->created_by===$request->user()->id,403,'The applicant cannot approve their own leave.');
        $data=$request->validate(['decision'=>'required|in:approved,rejected','decision_note'=>'nullable|string|max:1000']);
        $leave->update(['status'=>$data['decision'],'approved_by'=>$request->user()->id,'approved_at'=>now(),'decision_note'=>$data['decision_note']??null]);
        return back()->with('status','Leave application '.$data['decision'].'.');
    }
    public function storeAttendance(Request $request) {
        CompanyAuthorizer::requirePermission($request,'hr.manage');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'employee_id'=>['required','integer',Rule::exists('employees','id')->where('company_id',$companyId)],
            'work_date'=>'required|date','clock_in'=>'nullable|date_format:H:i','clock_out'=>'nullable|date_format:H:i',
            'hours'=>'required|numeric|min:0|max:24','notes'=>'nullable|string|max:1000',
        ]);
        $data['company_id']=$companyId; $data['recorded_by']=$request->user()->id;
        AttendanceEntry::updateOrCreate(['employee_id'=>$data['employee_id'],'work_date'=>$data['work_date']],$data);
        return back()->with('status','Attendance entry saved.');
    }
}