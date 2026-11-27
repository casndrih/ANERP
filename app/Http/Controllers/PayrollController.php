<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class PayrollController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        return view('payroll.index',['runs'=>PayrollRun::with('lines.employee')->where('company_id',$companyId)->latest()->paginate(15),'employees'=>Employee::where('company_id',$companyId)->where('status','active')->orderBy('last_name')->get()]);
    }
    public function store(Request $request) {
        CompanyAuthorizer::requireRole($request,['owner','admin','payroll_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate(['period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start','payment_date'=>'nullable|date']);
        $employees=Employee::where('company_id',$companyId)->where('status','active')->get();
        abort_if($employees->isEmpty(),422,'Add active employees before creating a payroll run.');
        DB::transaction(function() use($data,$employees,$companyId,$request) {
            $run=PayrollRun::create([
                'company_id'=>$companyId,'run_number'=>'PAY-'.now()->format('YmdHis'),
                'period_start'=>$data['period_start'],'period_end'=>$data['period_end'],
                'payment_date'=>$data['payment_date']??null,'created_by'=>$request->user()->id,'currency'=>'PGK',
            ]);
            foreach($employees as $employee) {
                $base=(float)$employee->base_salary;
                $gross=$base;
                // Statutory PAYE and superannuation are intentionally not calculated until company rules are verified.
                PayrollLine::create([
                    'payroll_run_id'=>$run->id,'employee_id'=>$employee->id,'base_pay'=>$base,
                    'gross_pay'=>$gross,'net_pay'=>$gross,
                    'calculation_note'=>'Base salary only. Configure and independently verify statutory PAYE, employee deductions and employer superannuation before approval.',
                ]);
            }
        });
        return back()->with('status','Draft payroll run generated. Statutory deductions are not calculated.');
    }
    public function approve(Request $request, PayrollRun $run) {
        CompanyAuthorizer::requireRole($request,['owner','admin','payroll_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        abort_unless($run->company_id===$companyId,404);
        abort_unless($run->status==='draft',422,'Only draft payroll runs can be approved.');
        abort_if($run->created_by===$request->user()->id,403,'The payroll creator cannot approve their own run.');
        abort_if($run->lines()->count()===0,422,'Payroll run has no employee lines.');
        $run->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now()]);
        return back()->with('status','Payroll run approved. Payment execution and accounting posting are not enabled in this phase.');
    }
    public function payslip(Request $request, PayrollLine $line) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $line->load(['employee','run']);
        abort_unless($line->run->company_id===$companyId,404);
        abort_unless($line->run->status==='approved',403,'Payslips are available after payroll approval.');
        return view('payroll.payslip',['line'=>$line]);
    }
}