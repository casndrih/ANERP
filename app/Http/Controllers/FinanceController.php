<?php
namespace App\Http\Controllers;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller {
    private function company(Request $request): int { return CompanyAuthorizer::activeCompanyId($request); }
    private function financeRole(Request $request): void { CompanyAuthorizer::requirePermission($request,'finance.create'); }
    private function branchId(Request $request, ?int $requested = null): ?int {
        $assigned=CompanyAuthorizer::activeBranchId($request);
        if($assigned!==null) { abort_if($requested!==null && $requested!==$assigned,403,'You cannot assign accounting records to another branch.'); return $assigned; }
        if($requested===null) return null;
        $valid=DB::table('branches')->where('company_id',$this->company($request))->where('id',$requested)->where('is_active',true)->exists();
        abort_unless($valid,422,'Select an active branch belonging to this company.');
        return $requested;
    }
    private function seedChart(int $companyId): void {
        $rows=[
            ['1000','Cash at bank','asset','debit',true],['1100','Accounts receivable','asset','debit',true],
            ['1200','Inventory','asset','debit',true],['1300','GST input tax','asset','debit',false],
            ['2000','Accounts payable','liability','credit',true],['2100','GST output tax','liability','credit',false],
            ['2200','PAYE payable','liability','credit',true],['2300','Employee super payable','liability','credit',true],
            ['2400','Employer super payable','liability','credit',true],['3000','Owner equity','equity','credit',false],
            ['3100','Retained earnings','equity','credit',false],['4000','Sales revenue','income','credit',false],
            ['4100','Other income','income','credit',false],['5000','Cost of goods sold','expense','debit',false],
            ['6000','Salaries and wages','expense','debit',false],['6100','Employer superannuation expense','expense','debit',false],
            ['6200','Rent expense','expense','debit',false],['6300','Utilities expense','expense','debit',false],
            ['6400','General operating expense','expense','debit',false],
        ];
        foreach($rows as [$code,$name,$type,$normal,$control]) Account::firstOrCreate(
            ['company_id'=>$companyId,'account_code'=>$code],
            ['name'=>$name,'account_type'=>$type,'normal_balance'=>$normal,'is_control_account'=>$control,'is_active'=>true]
        );
    }
    public function index(Request $request) {
        CompanyAuthorizer::requirePermission($request,'finance.view');
        $companyId=$this->company($request); $branchId=CompanyAuthorizer::activeBranchId($request); $this->seedChart($companyId);
        return view('finance.index',[
            'accounts'=>Account::where('company_id',$companyId)->where('is_active',true)->orderBy('account_code')->get(),
            'branches'=>DB::table('branches')->where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get(),
            'assignedBranchId'=>$branchId,
            'journals'=>JournalEntry::with('lines.account')->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->latest('journal_date')->limit(30)->get(),
            'suppliers'=>Supplier::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get(),
            'customers'=>Customer::where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get(),
            'bills'=>SupplierBill::with('supplier')->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->latest('bill_date')->limit(20)->get(),
            'invoices'=>CustomerInvoice::with('customer')->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->latest('invoice_date')->limit(20)->get(),
            'bankAccounts'=>BankAccount::with(['transactions'=>fn($q)=>$q->when($branchId,fn($t)=>$t->where('branch_id',$branchId))])->where('company_id',$companyId)->where('is_active',true)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->get(),
            'bankTransactions'=>BankTransaction::with('bankAccount')->where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->latest('transaction_date')->limit(30)->get(),
        ]);
    }
    public function storeCustomer(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $data=$request->validate(['code'=>['required','string','max:50',Rule::unique('customers')->where(fn($q)=>$q->where('company_id',$companyId))],'name'=>'required|string|max:180','tin'=>'nullable|string|max:80','email'=>'nullable|email','phone'=>'nullable|string|max:50','billing_address'=>'nullable|string|max:1000']);
        Customer::create($data+['company_id'=>$companyId,'is_active'=>true]);
        return back()->with('status','Customer created.');
    }
    public function storeJournal(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $data=$request->validate(['branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],'journal_date'=>'required|date','description'=>'required|string|max:1000','lines'=>'required|array|min:2|max:50','lines.*.account_id'=>['required','integer',Rule::exists('accounts','id')->where('company_id',$companyId)],'lines.*.debit'=>'required|numeric|min:0','lines.*.credit'=>'required|numeric|min:0']);
        $debits=round(collect($data['lines'])->sum(fn($l)=>(float)$l['debit']),2);
        $credits=round(collect($data['lines'])->sum(fn($l)=>(float)$l['credit']),2);
        if(abs($debits-$credits)>0.009 || $debits<=0) throw ValidationException::withMessages(['lines'=>'Journal must have at least one debit and equal total debits and credits.']);
        foreach($data['lines'] as $line) if(((float)$line['debit']>0)===((float)$line['credit']>0)) throw ValidationException::withMessages(['lines'=>'Each journal line must contain either a debit or a credit, not both.']);
        $branchId=$this->branchId($request,isset($data['branch_id'])?(int)$data['branch_id']:null);
        DB::transaction(function() use($data,$companyId,$request,$branchId) {
            $journal=JournalEntry::create(['company_id'=>$companyId,'branch_id'=>$branchId,'journal_number'=>'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'journal_date'=>$data['journal_date'],'source'=>'manual','status'=>'draft','description'=>$data['description'],'created_by'=>$request->user()->id]);
            foreach($data['lines'] as $line) JournalLine::create(['journal_entry_id'=>$journal->id,'account_id'=>$line['account_id'],'description'=>$data['description'],'debit'=>$line['debit'],'credit'=>$line['credit']]);
        });
        return back()->with('status','Balanced draft journal created. A different finance user must post it.');
    }
    public function postJournal(Request $request, int $journal) {
        $this->financeRole($request);
        CompanyAuthorizer::requirePermission($request,'finance.post'); $companyId=$this->company($request); $branchId=CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function() use($journal,$companyId,$request,$branchId) {
            $entry=JournalEntry::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->lockForUpdate()->findOrFail($journal);
            abort_unless($entry->status==='draft',409,'Only draft journals can be posted.');
            abort_if((int)$entry->created_by===(int)$request->user()->id,403,'The journal creator cannot post their own journal.');
            $lines=$entry->lines()->get();
            $debits=round((float)$lines->sum('debit'),2); $credits=round((float)$lines->sum('credit'),2);
            abort_if($lines->count()<2 || $debits<=0 || abs($debits-$credits)>0.009,422,'Journal is not balanced.');
            $entry->update(['status'=>'posted','posted_by'=>$request->user()->id,'posted_at'=>now()]);
        });
        return back()->with('status','Journal posted to the general ledger.');
    }
    public function storeSupplierBill(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $this->seedChart($companyId);
        $data=$request->validate(['branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],'supplier_id'=>['required','integer',Rule::exists('suppliers','id')->where('company_id',$companyId)],'bill_number'=>['required','string','max:80',Rule::unique('supplier_bills')->where(fn($q)=>$q->where('company_id',$companyId))],'bill_date'=>'required|date','due_date'=>'nullable|date|after_or_equal:bill_date','expense_account_id'=>['required','integer',Rule::exists('accounts','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('account_type','expense'))],'amount'=>'required|numeric|gt:0','description'=>'required|string|max:255']);
        $branchId=$this->branchId($request,isset($data['branch_id'])?(int)$data['branch_id']:null);
        SupplierBill::create(array_merge($data,['company_id'=>$companyId,'branch_id'=>$branchId,'currency'=>'PGK','status'=>'draft','created_by'=>$request->user()->id]));
        return back()->with('status','Supplier bill saved as draft.');
    }
    public function postSupplierBill(Request $request, int $bill) {
        $this->financeRole($request);
        CompanyAuthorizer::requirePermission($request,'finance.post'); $companyId=$this->company($request); $branchId=CompanyAuthorizer::activeBranchId($request); $this->seedChart($companyId);
        DB::transaction(function() use($bill,$companyId,$request,$branchId) {
            $doc=SupplierBill::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->lockForUpdate()->findOrFail($bill);
            abort_unless($doc->status==='draft',409,'Only draft supplier bills can be posted.');
            abort_if((int)$doc->created_by===(int)$request->user()->id,403,'The bill creator cannot post their own bill.');
            $ap=Account::where('company_id',$companyId)->where('account_code','2000')->firstOrFail();
            $j=$this->makePostedJournal($companyId,$doc->branch_id,$doc->bill_date,'supplier_bill',$doc->description,$doc->created_by,$request->user()->id,[
                [$doc->expense_account_id,$doc->amount,0],[$ap->id,0,$doc->amount]
            ]);
            $doc->update(['status'=>'posted','journal_entry_id'=>$j->id,'posted_by'=>$request->user()->id,'posted_at'=>now()]);
        });
        return back()->with('status','Supplier bill posted: expense debit and accounts payable credit.');
    }
    public function storeInvoice(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $this->seedChart($companyId);
        $data=$request->validate(['branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],'customer_id'=>['required','integer',Rule::exists('customers','id')->where('company_id',$companyId)],'invoice_number'=>['required','string','max:80',Rule::unique('customer_invoices')->where(fn($q)=>$q->where('company_id',$companyId))],'invoice_date'=>'required|date','due_date'=>'nullable|date|after_or_equal:invoice_date','revenue_account_id'=>['required','integer',Rule::exists('accounts','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('account_type','income'))],'amount'=>'required|numeric|gt:0','description'=>'required|string|max:255']);
        $branchId=$this->branchId($request,isset($data['branch_id'])?(int)$data['branch_id']:null);
        CustomerInvoice::create(array_merge($data,['company_id'=>$companyId,'branch_id'=>$branchId,'currency'=>'PGK','status'=>'draft','created_by'=>$request->user()->id]));
        return back()->with('status','Customer invoice saved as draft.');
    }
    public function postInvoice(Request $request, int $invoice) {
        $this->financeRole($request);
        CompanyAuthorizer::requirePermission($request,'finance.post'); $companyId=$this->company($request); $branchId=CompanyAuthorizer::activeBranchId($request); $this->seedChart($companyId);
        DB::transaction(function() use($invoice,$companyId,$request,$branchId) {
            $doc=CustomerInvoice::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->lockForUpdate()->findOrFail($invoice);
            abort_unless($doc->status==='draft',409,'Only draft customer invoices can be posted.');
            abort_if((int)$doc->created_by===(int)$request->user()->id,403,'The invoice creator cannot post their own invoice.');
            $ar=Account::where('company_id',$companyId)->where('account_code','1100')->firstOrFail();
            $j=$this->makePostedJournal($companyId,$doc->branch_id,$doc->invoice_date,'customer_invoice',$doc->description,$doc->created_by,$request->user()->id,[
                [$ar->id,$doc->amount,0],[$doc->revenue_account_id,0,$doc->amount]
            ]);
            $doc->update(['status'=>'posted','journal_entry_id'=>$j->id,'posted_by'=>$request->user()->id,'posted_at'=>now()]);
        });
        return back()->with('status','Customer invoice posted: accounts receivable debit and revenue credit.');
    }
    private function makePostedJournal(int $companyId,?int $branchId,$date,string $source,string $description,int $creator,int $poster,array $lines): JournalEntry {
        $entry=JournalEntry::create(['company_id'=>$companyId,'branch_id'=>$branchId,'journal_number'=>'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'journal_date'=>$date,'source'=>$source,'status'=>'posted','description'=>$description,'created_by'=>$creator,'posted_by'=>$poster,'posted_at'=>now()]);
        foreach($lines as [$account,$debit,$credit]) JournalLine::create(['journal_entry_id'=>$entry->id,'account_id'=>$account,'description'=>$description,'debit'=>$debit,'credit'=>$credit]);
        return $entry;
    }
    public function storeBankAccount(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $data=$request->validate(['branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],'code'=>['required','string','max:40',Rule::unique('bank_accounts')->where(fn($q)=>$q->where('company_id',$companyId))],'name'=>'required|string|max:120','bank_name'=>'nullable|string|max:120','account_number'=>'nullable|string|max:100','account_id'=>['required','integer',Rule::exists('accounts','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('account_type','asset'))]]);
        $branchId=$this->branchId($request,isset($data['branch_id'])?(int)$data['branch_id']:null);
        BankAccount::create(array_merge($data,['company_id'=>$companyId,'branch_id'=>$branchId,'currency'=>'PGK','is_active'=>true]));
        return back()->with('status','Bank account linked to its general-ledger asset account.');
    }
    public function storeBankTransaction(Request $request) {
        $this->financeRole($request); $companyId=$this->company($request);
        $data=$request->validate(['bank_account_id'=>['required','integer',Rule::exists('bank_accounts','id')->where('company_id',$companyId)],'transaction_date'=>'required|date','reference'=>'nullable|string|max:100','description'=>'required|string|max:255','direction'=>'required|in:in,out','amount'=>'required|numeric|gt:0','offset_account_id'=>['required','integer',Rule::exists('accounts','id')->where('company_id',$companyId)]]);
        DB::transaction(function() use($data,$companyId,$request) {
            $branchId=CompanyAuthorizer::activeBranchId($request);
            $bank=BankAccount::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->findOrFail($data['bank_account_id']);
            $offset=Account::where('company_id',$companyId)->where('is_active',true)->findOrFail($data['offset_account_id']);
            if ((int)$offset->id === (int)$bank->account_id) throw ValidationException::withMessages(['offset_account_id'=>'Choose a different offset account from the bank ledger account.']);
            $debitBank=$data['direction']==='in';
            $creator=(int)$request->user()->id;
            $journal=JournalEntry::create(['company_id'=>$companyId,'branch_id'=>$bank->branch_id,'journal_number'=>'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'journal_date'=>$data['transaction_date'],'source'=>'bank_transaction','status'=>'draft','description'=>$data['description'],'created_by'=>$creator]);
            JournalLine::create(['journal_entry_id'=>$journal->id,'account_id'=>$bank->account_id,'description'=>$data['description'],'debit'=>$debitBank?$data['amount']:0,'credit'=>$debitBank?0:$data['amount']]);
            JournalLine::create(['journal_entry_id'=>$journal->id,'account_id'=>$offset->id,'description'=>$data['description'],'debit'=>$debitBank?0:$data['amount'],'credit'=>$debitBank?$data['amount']:0]);
            BankTransaction::create(['company_id'=>$companyId,'branch_id'=>$bank->branch_id,'bank_account_id'=>$bank->id,'journal_entry_id'=>$journal->id,'transaction_date'=>$data['transaction_date'],'reference'=>$data['reference']??null,'description'=>$data['description'],'direction'=>$data['direction'],'amount'=>$data['amount'],'created_by'=>$creator]);
        });
        return back()->with('status','Bank transaction recorded with a draft double-entry journal. Have another finance user post the journal before reconciliation.');
    }
    public function reconcile(Request $request, int $bankAccount) {
        $this->financeRole($request);
        CompanyAuthorizer::requirePermission($request,'finance.reconcile'); $companyId=$this->company($request);
        $data=$request->validate(['statement_start'=>'required|date','statement_end'=>'required|date|after_or_equal:statement_start','opening_balance'=>'required|numeric','closing_balance'=>'required|numeric','transaction_ids'=>'required|array|min:1','transaction_ids.*'=>'integer']);
        DB::transaction(function() use($data,$bankAccount,$companyId,$request) {
            $branchId=CompanyAuthorizer::activeBranchId($request);
            $bank=BankAccount::where('company_id',$companyId)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->findOrFail($bankAccount);
            $transactions=BankTransaction::where('company_id',$companyId)->where('bank_account_id',$bank->id)->where('branch_id',$bank->branch_id)->whereIn('id',$data['transaction_ids'])->where('is_reconciled',false)->whereBetween('transaction_date',[$data['statement_start'],$data['statement_end']])->with('journalEntry')->lockForUpdate()->get();
            if(count(array_unique($data['transaction_ids']))!==count($data['transaction_ids']) || $transactions->count()!==count($data['transaction_ids'])) throw ValidationException::withMessages(['transaction_ids'=>'Select only unreconciled transactions belonging to this bank account and statement period.']);
            foreach($transactions as $tx) {
                $posted=JournalEntry::where('company_id',$companyId)->whereKey($tx->journal_entry_id)->where('status','posted')->exists();
                if(!$posted) throw ValidationException::withMessages(['transaction_ids'=>'Every selected bank transaction must have a posted journal.']);
            }
            $net=$transactions->sum(fn($tx)=>$tx->direction==='in'?(float)$tx->amount:-(float)$tx->amount);
            $calculated=round((float)$data['opening_balance']+$net,2);
            if(abs($calculated-(float)$data['closing_balance'])>0.009) throw ValidationException::withMessages(['closing_balance'=>'Statement closing balance does not match opening balance plus selected cleared transactions.']);
            $rec=BankReconciliation::create(['company_id'=>$companyId,'branch_id'=>$bank->branch_id,'bank_account_id'=>$bank->id,'statement_start'=>$data['statement_start'],'statement_end'=>$data['statement_end'],'opening_balance'=>$data['opening_balance'],'closing_balance'=>$data['closing_balance'],'reconciled_balance'=>$calculated,'status'=>'completed','reconciled_by'=>$request->user()->id,'reconciled_at'=>now()]);
            BankTransaction::whereIn('id',$transactions->pluck('id'))->update(['is_reconciled'=>true,'reconciliation_id'=>$rec->id]);
        });
        return back()->with('status','Bank statement reconciled. Selected posted transactions balance to the statement.');
    }
}