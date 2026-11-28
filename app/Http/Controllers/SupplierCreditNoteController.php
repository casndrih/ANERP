<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\SupplierPaymentAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierCreditNoteController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $expenseAccountIds = Account::where('company_id', $companyId)->where('account_type', 'expense')->where('normal_balance', 'debit')->pluck('id');
        $bills = SupplierBill::with('supplier')->where('company_id', $companyId)->where('status', 'posted')
            ->where('invoice_type', 'general')->where('match_status', 'not_required')->whereIn('expense_account_id', $expenseAccountIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('supplier_bills.*')
            ->selectSub(function ($q) {
                $q->from('supplier_payment_allocations')->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                    ->whereColumn('supplier_payment_allocations.supplier_bill_id', 'supplier_bills.id')->whereIn('supplier_payments.status', ['draft', 'posted'])
                    ->whereColumn('supplier_payments.company_id', 'supplier_bills.company_id')->selectRaw('COALESCE(SUM(supplier_payment_allocations.amount), 0)');
            }, 'amount_paid')
            ->selectSub(function ($q) {
                $q->from('supplier_credit_notes')->whereColumn('supplier_credit_notes.supplier_bill_id', 'supplier_bills.id')
                    ->whereIn('supplier_credit_notes.status', ['draft', 'posted'])->whereColumn('supplier_credit_notes.company_id', 'supplier_bills.company_id')
                    ->selectRaw('COALESCE(SUM(supplier_credit_notes.amount), 0)');
            }, 'amount_credited')
            ->orderBy('due_date')->get()
            ->filter(fn ($bill) => round((float) $bill->amount - (float) $bill->amount_paid - (float) $bill->amount_credited, 2) > 0);
        $notes = SupplierCreditNote::with(['supplier', 'bill'])->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->latest('credit_note_date')->limit(40)->get();
        return view('finance.supplier-credit-notes.index', compact('bills', 'notes'));
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'bill_id' => ['required', 'integer', Rule::exists('supplier_bills', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->where('invoice_type', 'general')->where('match_status', 'not_required'))],
            'credit_note_number' => ['required', 'string', 'max:80', Rule::unique('supplier_credit_notes')->where(fn ($q) => $q->where('company_id', $companyId))],
            'credit_note_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $bill = SupplierBill::where('company_id', $companyId)->where('status', 'posted')
                ->where('invoice_type', 'general')->where('match_status', 'not_required')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->lockForUpdate()->findOrFail($data['bill_id']);
            if ($bill->currency !== 'PGK') throw ValidationException::withMessages(['bill_id' => 'Supplier credit notes currently support PGK bills only.']);
            if ($data['credit_note_date'] < $bill->bill_date->toDateString()) {
                throw ValidationException::withMessages(['credit_note_date' => 'Credit note date cannot be earlier than the original supplier bill date.']);
            }
            $paid = (float) SupplierPaymentAllocation::query()->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)->where('supplier_payments.company_id', $companyId)
                ->whereIn('supplier_payments.status', ['draft', 'posted'])->sum('supplier_payment_allocations.amount');
            $reservedCredits = (float) SupplierCreditNote::where('company_id', $companyId)->where('supplier_bill_id', $bill->id)
                ->whereIn('status', ['draft', 'posted'])->sum('amount');
            $amount = round((float) $data['amount'], 2);
            if ($amount > round((float) $bill->amount - $paid - $reservedCredits, 2) + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Supplier credit note exceeds the outstanding bill balance after payments and existing credit notes.']);
            }
            $expense = Account::where('company_id', $companyId)->whereKey($bill->expense_account_id)->firstOrFail();
            if ($expense->account_type !== 'expense' || $expense->normal_balance !== 'debit') {
                throw ValidationException::withMessages(['bill_id' => 'This bill is not a general expense bill. Inventory and GRNI credit notes require a stock-return workflow.']);
            }
            $rate = (float) ($bill->gst_rate ?? 0);
            $netAmount = $rate > 0 ? round($amount / (1 + $rate / 100), 2) : $amount;
            $gstAmount = round($amount - $netAmount, 2);
            if ($netAmount > (float) ($bill->net_amount ?? $bill->amount) + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Credit note net value cannot exceed the original supplier bill net value.']);
            }
            SupplierCreditNote::create([
                'company_id' => $companyId, 'branch_id' => $bill->branch_id, 'supplier_id' => $bill->supplier_id,
                'supplier_bill_id' => $bill->id, 'credit_note_number' => $data['credit_note_number'],
                'credit_note_date' => $data['credit_note_date'], 'description' => $data['description'],
                'amount' => $amount, 'net_amount' => $netAmount, 'gst_amount' => $gstAmount,
                'gst_rate' => $rate, 'gst_treatment' => $bill->gst_treatment ?? 'out_of_scope',
                'currency' => 'PGK', 'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
        });
        return back()->with('status', 'Supplier credit note saved as a draft. A different finance user must post it.');
    }

    public function cancel(Request $request, int $creditNote)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($creditNote, $companyId, $branchId) {
            $note = SupplierCreditNote::where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($creditNote);
            abort_unless($note->status === 'draft', 409, 'Only draft supplier credit notes can be cancelled.');
            $note->update(['status' => 'cancelled']);
        });
        return back()->with('status', 'Draft supplier credit note cancelled; its bill balance has been released.');
    }

    public function post(Request $request, int $creditNote)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($creditNote, $companyId, $branchId, $request) {
            $note = SupplierCreditNote::where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($creditNote);
            abort_unless($note->status === 'draft', 409, 'Only draft supplier credit notes can be posted.');
            abort_if((int) $note->created_by === (int) $request->user()->id, 403, 'The credit note creator cannot post their own credit note.');
            $bill = SupplierBill::where('company_id', $companyId)->lockForUpdate()->findOrFail($note->supplier_bill_id);
            abort_unless($bill->status === 'posted' && $bill->invoice_type === 'general' && $bill->match_status === 'not_required', 409, 'Credit note must reference a posted non-inventory general bill.');
            abort_unless($bill->currency === 'PGK' && (int) $bill->supplier_id === (int) $note->supplier_id, 409, 'Credit note supplier and currency must match the original bill.');
            abort_unless((int) ($bill->branch_id ?? 0) === (int) ($note->branch_id ?? 0), 409, 'Credit note and bill branch attribution must match.');
            $paid = (float) SupplierPaymentAllocation::query()->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)->where('supplier_payments.company_id', $companyId)
                ->whereIn('supplier_payments.status', ['draft', 'posted'])->sum('supplier_payment_allocations.amount');
            $otherCredits = (float) SupplierCreditNote::where('company_id', $companyId)->where('supplier_bill_id', $bill->id)
                ->whereIn('status', ['draft', 'posted'])->where('id', '!=', $note->id)->sum('amount');
            if ((float) $note->amount > round((float) $bill->amount - $paid - $otherCredits, 2) + 0.009) {
                throw ValidationException::withMessages(['credit_note' => 'Posting would credit more than the supplier bill outstanding balance.']);
            }
            if (abs(round((float) $note->net_amount + (float) $note->gst_amount, 2) - (float) $note->amount) > 0.009) {
                throw ValidationException::withMessages(['credit_note' => 'Credit note net, GST and gross amounts do not reconcile.']);
            }
            if (abs((float) $note->gst_rate - (float) ($bill->gst_rate ?? 0)) > 0.009 || ($note->gst_treatment ?? 'out_of_scope') !== ($bill->gst_treatment ?? 'out_of_scope')) {
                throw ValidationException::withMessages(['credit_note' => 'Credit note GST treatment must match the original supplier bill.']);
            }
            $ap = Account::where('company_id', $companyId)->where('account_code', '2000')->firstOrFail();
            $expense = Account::where('company_id', $companyId)->whereKey($bill->expense_account_id)->firstOrFail();
            abort_unless($ap->account_type === 'liability' && $ap->normal_balance === 'credit', 422, 'AP control account 2000 must be a credit-normal liability.');
            abort_unless($expense->account_type === 'expense' && $expense->normal_balance === 'debit', 422, 'Supplier credit note must reverse a general expense account.');
            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $note->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $note->credit_note_date, 'source' => 'supplier_credit_note', 'status' => 'posted',
                'description' => 'Supplier credit note '.$note->credit_note_number.' · '.$note->description,
                'created_by' => $note->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ap->id, 'description' => 'Supplier credit note '.$note->credit_note_number, 'debit' => $note->amount, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $expense->id, 'description' => 'Reversal of supplier expense', 'debit' => 0, 'credit' => $note->net_amount]);
            if ((float) $note->gst_amount > 0) {
                $inputTax = Account::where('company_id', $companyId)->where('account_code', '1300')->firstOrFail();
                abort_unless($inputTax->account_type === 'asset' && $inputTax->normal_balance === 'debit', 422, 'GST input account 1300 must be a debit-normal asset.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $inputTax->id, 'description' => 'Reverse supplier input GST', 'debit' => 0, 'credit' => $note->gst_amount]);
            }
            $note->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'posted_by' => $request->user()->id, 'posted_at' => now()]);
        });
        return back()->with('status', 'Supplier credit note posted to accounts payable and the original expense account.');
    }
}
