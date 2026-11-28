<?php
namespace App\Http\Controllers;
use App\Models\Account;
use App\Models\CustomerInvoice;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use App\Models\StockMovement;
use App\Models\SupplierBill;
use App\Support\CompanyAuthorizer;
use App\Services\ReportingCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function index(Request $request) {
        CompanyAuthorizer::requirePermission($request,'reports.view');
        $companyId=CompanyAuthorizer::activeCompanyId($request);
        return view('reports.index',$this->reportData($request,$companyId));
    }
    public function export(Request $request): StreamedResponse {
        CompanyAuthorizer::requirePermission($request,'reports.export');
        $data=$this->reportData($request,CompanyAuthorizer::activeCompanyId($request));
        return response()->streamDownload(function() use($data) {
            $out=fopen('php://output','w');
            fputcsv($out,['ANERP',strtoupper(str_replace('_',' ',$data['report']))]);
            fputcsv($out,['Currency','PGK','From',$data['from']->toDateString(),'To',$data['to']->toDateString()]);
            if($data['rows']->isEmpty()) fputcsv($out,['No records']);
            else { fputcsv($out,array_keys((array)$data['rows']->first())); foreach($data['rows'] as $row) fputcsv($out,array_values((array)$row)); }
            fclose($out);
        },'anerp-'.$data['report'].'-'.$data['to']->format('Ymd').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
    private function reportData(Request $request,int $companyId): array {
        $v=$request->validate(['report'=>'nullable|in:trial_balance,profit_loss,balance_sheet,cash_flow,receivables,payables,payroll,inventory','from'=>'nullable|date','to'=>'nullable|date']);
        $to=Carbon::parse($v['to']??now()->toDateString())->endOfDay();
        $from=Carbon::parse($v['from']??$to->copy()->startOfMonth()->toDateString())->startOfDay();
        abort_if($from->gt($to),422,'Start date must not be after end date.');
        $report=$v['report']??'trial_balance';
        if (in_array($report,['trial_balance','profit_loss','balance_sheet','cash_flow','receivables','payables'],true)) {
            CompanyAuthorizer::requirePermission($request,'finance.view');
        } elseif ($report==='payroll') {
            CompanyAuthorizer::requirePermission($request,'payroll.view');
        } elseif ($report==='inventory') {
            CompanyAuthorizer::requirePermission($request,'inventory.view');
        }
        $branchId=CompanyAuthorizer::activeBranchId($request);
        $rows=collect(); $totals=[];
        if(in_array($report,['trial_balance','profit_loss','balance_sheet','cash_flow'],true)) {
            $q=JournalLine::query()->join('journal_entries','journal_entries.id','=','journal_lines.journal_entry_id')
                ->join('accounts','accounts.id','=','journal_lines.account_id')
                ->where('journal_entries.company_id',$companyId)->where('journal_entries.status','posted')
                ->when($branchId,fn($query)=>$query->where('journal_entries.branch_id',$branchId));
            if(in_array($report,['profit_loss','cash_flow'],true)) $q->whereBetween('journal_entries.journal_date',[$from->toDateString(),$to->toDateString()]);
            else $q->whereDate('journal_entries.journal_date','<=',$to->toDateString());
            $balances=$q->select('accounts.id','accounts.account_code','accounts.name','accounts.account_type','accounts.normal_balance')
                ->selectRaw('SUM(journal_lines.debit) as debit_total')->selectRaw('SUM(journal_lines.credit) as credit_total')
                ->groupBy('accounts.id','accounts.account_code','accounts.name','accounts.account_type','accounts.normal_balance')->orderBy('accounts.account_code')->get();
            $rows=$balances->map(function($a) {
                $dr=round((float)$a->debit_total,2); $cr=round((float)$a->credit_total,2);
                return (object)['code'=>$a->account_code,'account'=>$a->name,'type'=>$a->account_type,'debit'=>$dr,'credit'=>$cr,'balance'=>round($a->normal_balance==='credit'?$cr-$dr:$dr-$cr,2)];
            });
            if($report==='profit_loss') $rows=$rows->whereIn('type',['income','expense'])->values();
            if($report==='balance_sheet') {
                $rows=$rows->whereIn('type',['asset','liability','equity'])->values();
                $earnings=ReportingCalculator::netIncome($balances->whereIn('account_type',['income','expense']));
                $rows->push((object)['code'=>'CURRENT-EARNINGS','account'=>'Accumulated earnings (calculated)','type'=>'equity','debit'=>0,'credit'=>0,'balance'=>round($earnings,2)]);
            }
            if($report==='cash_flow') {
                $bankIds=DB::table('bank_accounts')->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->pluck('account_id')->all();
                $rows=$balances->whereIn('id',$bankIds)->map(fn($a)=>(object)['code'=>$a->account_code,'account'=>$a->name,'type'=>'bank movement','debit'=>round((float)$a->debit_total,2),'credit'=>round((float)$a->credit_total,2),'balance'=>round((float)$a->debit_total-(float)$a->credit_total,2)])->values();
            }
            $totals=['debit'=>round((float)$rows->sum('debit'),2),'credit'=>round((float)$rows->sum('credit'),2),'balance'=>round((float)$rows->sum('balance'),2)];
        } elseif(in_array($report,['receivables','payables'],true)) {
            $receivable=$report==='receivables'; $model=$receivable?CustomerInvoice::class:SupplierBill::class;
            $dateCol=$receivable?'invoice_date':'bill_date'; $relation=$receivable?'customer':'supplier';
            $docs=$model::with($relation)->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->where('status','posted')->whereDate($dateCol,'<=',$to->toDateString())->get();
            $paidByInvoice = $receivable
                ? DB::table('customer_receipt_allocations')->join('customer_receipts','customer_receipts.id','=','customer_receipt_allocations.customer_receipt_id')->whereIn('customer_receipt_allocations.customer_invoice_id',$docs->pluck('id'))->where('customer_receipts.status','posted')->where('customer_receipts.company_id',$companyId)->whereDate('customer_receipts.receipt_date','<=',$to->toDateString())->select('customer_receipt_allocations.customer_invoice_id')->selectRaw('SUM(customer_receipt_allocations.amount) as paid_amount')->groupBy('customer_receipt_allocations.customer_invoice_id')->get()->pluck('paid_amount','customer_invoice_id')
                : DB::table('supplier_payment_allocations')->join('supplier_payments','supplier_payments.id','=','supplier_payment_allocations.supplier_payment_id')->whereIn('supplier_payment_allocations.supplier_bill_id',$docs->pluck('id'))->where('supplier_payments.status','posted')->where('supplier_payments.company_id',$companyId)->whereDate('supplier_payments.payment_date','<=',$to->toDateString())->select('supplier_payment_allocations.supplier_bill_id')->selectRaw('SUM(supplier_payment_allocations.amount) as paid_amount')->groupBy('supplier_payment_allocations.supplier_bill_id')->get()->pluck('paid_amount','supplier_bill_id');
            $creditedByInvoice = $receivable
                ? DB::table('customer_credit_notes')->whereIn('customer_invoice_id',$docs->pluck('id'))->where('company_id',$companyId)->where('status','posted')->whereDate('credit_note_date','<=',$to->toDateString())->select('customer_invoice_id')->selectRaw('SUM(amount) as credited_amount')->groupBy('customer_invoice_id')->get()->pluck('credited_amount','customer_invoice_id')
                : collect();
            $rows=$docs->map(function($d) use($receivable,$relation,$dateCol,$to,$paidByInvoice,$creditedByInvoice) {
                $due=Carbon::parse($d->due_date??$d->{$dateCol}); $days=ReportingCalculator::ageingDays($due,$to); $amount=round((float)$d->amount - (float)$paidByInvoice->get($d->id,0) - (float)$creditedByInvoice->get($d->id,0),2);
                return (object)['document'=>$receivable?$d->invoice_number:$d->bill_number,'counterparty'=>$d->{$relation}?->name??'','document_date'=>$d->{$dateCol}?->format('Y-m-d'),'due_date'=>$due->toDateString(),'days_overdue'=>$days,'current'=>ReportingCalculator::ageingBucket($days)==='current'?$amount:0,'1_30'=>ReportingCalculator::ageingBucket($days)==='1_30'?$amount:0,'31_60'=>ReportingCalculator::ageingBucket($days)==='31_60'?$amount:0,'61_90'=>ReportingCalculator::ageingBucket($days)==='61_90'?$amount:0,'over_90'=>ReportingCalculator::ageingBucket($days)==='over_90'?$amount:0,'amount'=>$amount];
            })->filter(fn($row) => $row->amount > 0.009)->values();
            $totals=['amount'=>round((float)$rows->sum('amount'),2)];
        } elseif($report==='payroll') {
            $branchId=CompanyAuthorizer::activeBranchId($request);
            $runs=PayrollRun::with(['lines'=>function($query) use($branchId) {
                if($branchId!==null) $query->whereHas('employee',fn($employee)=>$employee->where('branch_id',$branchId));
                $query->with('employee');
            }])->where('company_id',$companyId)->where('status','approved')
                ->when($branchId,fn($q)=>$q->whereHas('lines.employee',fn($employee)=>$employee->where('branch_id',$branchId)))
                ->whereBetween('period_end',[$from->toDateString(),$to->toDateString()])->orderBy('period_end')->get();
            $rows=$runs->map(fn($r)=>(object)['run_number'=>$r->run_number,'period_start'=>$r->period_start?->format('Y-m-d'),'period_end'=>$r->period_end?->format('Y-m-d'),'employees'=>$r->lines->count(),'gross_pay'=>round((float)$r->lines->sum('gross_pay'),2),'paye'=>round((float)$r->lines->sum('tax_deduction'),2),'employee_super'=>round((float)$r->lines->sum('employee_super'),2),'employer_super'=>round((float)$r->lines->sum('employer_super'),2),'net_pay'=>round((float)$r->lines->sum('net_pay'),2)]);
            foreach(['gross_pay','paye','employee_super','employer_super','net_pay'] as $k) $totals[$k]=round((float)$rows->sum($k),2);
        } else {
            $branchId=CompanyAuthorizer::activeBranchId($request);
            $moves=StockMovement::with(['product','warehouse'])->where('company_id',$companyId)->when($branchId,fn($q)=>$q->whereHas('warehouse',fn($w)=>$w->where('branch_id',$branchId)))->whereBetween('occurred_at',[$from,$to])->orderBy('occurred_at')->get();
            $rows=$moves->groupBy(fn($m)=>($m->product?->sku??'—').' · '.($m->product?->name??'Unknown').' / '.($m->warehouse?->name??'Unknown'))->map(function($items,$label) {
                $in=$items->whereIn('movement_type',['opening','purchase_receipt','transfer_in','adjustment_in'])->sum('quantity');
                $out=$items->whereIn('movement_type',['issue','transfer_out','adjustment_out'])->sum('quantity');
                return (object)['product_warehouse'=>$label,'movements'=>$items->count(),'quantity_in'=>round((float)$in,3),'quantity_out'=>round((float)$out,3),'net_quantity'=>round((float)$in-(float)$out,3),'movement_cost_value'=>ReportingCalculator::movementCostValue($items)];
            })->values();
            foreach(['quantity_in','quantity_out','net_quantity','movement_cost_value'] as $k) $totals[$k]=round((float)$rows->sum($k),3);
        }
        return compact('companyId','from','to','report','rows','totals')+['reportOptions'=>['trial_balance'=>'Trial balance','profit_loss'=>'Profit & loss','balance_sheet'=>'Balance sheet','cash_flow'=>'Bank cash movement','receivables'=>'Accounts receivable ageing','payables'=>'Accounts payable ageing','payroll'=>'Payroll summary','inventory'=>'Inventory movement']];
    }
}