<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\Company;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\PngPayrollCalculator;
use App\Support\CompanyAuthorizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class PayrollController extends Controller {
    public function index(Request $request) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        return view('payroll.index',['runs'=>PayrollRun::with('lines.employee')->where('company_id',$companyId)->latest()->paginate(15),'employees'=>Employee::where('company_id',$companyId)->where('status','active')->orderBy('last_name')->get()]);
    }
    public function store(Request $request, PngPayrollCalculator $calculator) {
        CompanyAuthorizer::requireRole($request,['owner','admin','payroll_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $data=$request->validate([
            'period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start',
            'payment_date'=>'nullable|date','pay_frequency'=>'required|in:weekly,fortnightly,monthly',
        ]);
        $start=Carbon::parse($data['period_start'])->startOfDay();
        $end=Carbon::parse($data['period_end'])->startOfDay();
        $days=$start->diffInDays($end)+1;
        if (($data['pay_frequency']==='weekly' && $days!==7)
            || ($data['pay_frequency']==='fortnightly' && $days!==14)
            || ($data['pay_frequency']==='monthly' && ($days<28 || $days>31))) {
            throw ValidationException::withMessages(['period_end'=>'The selected dates do not match the chosen weekly, fortnightly or monthly pay period.']);
        }
        $employees=Employee::where('company_id',$companyId)->where('status','active')->where('pay_frequency',$data['pay_frequency'])->get();
        abort_if($employees->isEmpty(),422,'No active employees use this pay frequency.');
        $employerVoluntary=(bool)Company::whereKey($companyId)->value('superannuation_voluntary_participant');
        $companyHeadcount=Employee::where('company_id',$companyId)->where('status','active')
            ->where(fn($q)=>$q->whereNull('hire_date')->orWhereDate('hire_date','<=',$end))
            ->where(fn($q)=>$q->whereNull('termination_date')->orWhereDate('termination_date','>=',$start))->count();
        DB::transaction(function() use($data,$employees,$companyId,$request,$calculator,$days,$end,$companyHeadcount,$employerVoluntary) {
            $run=PayrollRun::create([
                'company_id'=>$companyId,'run_number'=>'PAY-'.now()->format('YmdHis'),
                'period_start'=>$data['period_start'],'period_end'=>$data['period_end'],
                'payment_date'=>$data['payment_date']??null,'created_by'=>$request->user()->id,
                'currency'=>'PGK','pay_frequency'=>$data['pay_frequency'],
                'statutory_rule_version'=>PngPayrollCalculator::VERSION,
            ]);
            foreach($employees as $employee) {
                $base=(float)$employee->base_salary;
                $allowances=0.0;
                $gross=$base+$allowances;
                $tax=$calculator->salaryAndWagesTax(
                    $gross,$employee->tax_residency,
                    (bool)$employee->employment_declaration_received,
                    (float)$employee->annual_dependant_tax_credit,$days
                );
                $serviceEligible=$employee->hire_date && $employee->hire_date->copy()->addMonths(3)->lte($end);
                $super=$calculator->superannuation(
                    $base,$companyHeadcount>=15,(bool)$serviceEligible,
                    $employerVoluntary,(bool)$employee->superannuation_opt_in,(bool)$employee->superannuation_exempt
                );
                $net=round($gross-$tax-$super['employee'],2);
                PayrollLine::create([
                    'payroll_run_id'=>$run->id,'employee_id'=>$employee->id,
                    'base_pay'=>$base,'allowances'=>$allowances,'gross_pay'=>$gross,
                    'taxable_gross'=>$gross,'tax_deduction'=>$tax,
                    'employee_super'=>$super['employee'],'employer_super'=>$super['employer'],
                    'net_pay'=>$net,'tax_rule_version'=>PngPayrollCalculator::VERSION,
                    'calculation_note'=>'PNG Income Tax Act 2025 salary and wages tax (Schedule 1 and Schedule 6). PAYE is withheld using the statutory fortnightly bands; weekly/monthly pay periods are normalized to fortnightly equivalents. Superannuation uses 6% employee and 8.4% employer rates on base pay when eligibility rules are met. Review exemptions, declarations and approved dependant credit before approval.',
                ]);
            }
        });
        return back()->with('status','Draft payroll run calculated with PNG statutory PAYE and superannuation rules. Review declarations, eligibility and each payslip before approval.');
    }
    public function approve(Request $request, PayrollRun $run) {
        CompanyAuthorizer::requireRole($request,['owner','admin','payroll_manager']);
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        abort_unless($run->company_id===$companyId,404);
        abort_unless($run->status==='draft',422,'Only draft payroll runs can be approved.');
        abort_if($run->created_by===$request->user()->id,403,'The payroll creator cannot approve their own run.');
        abort_if($run->lines()->count()===0,422,'Payroll run has no employee lines.');
        $run->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now()]);
        return back()->with('status','Payroll run approved. Payment execution and statutory return filing are not enabled.');
    }
    public function payslip(Request $request, PayrollLine $line) {
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        $line->load(['employee','run']);
        abort_unless($line->run->company_id===$companyId,404);
        abort_unless($line->run->status==='approved',403,'Payslips are available after payroll approval.');
        return view('payroll.payslip',['line'=>$line]);
    }
}