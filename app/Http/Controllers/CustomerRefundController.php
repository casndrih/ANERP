<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerRefund;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerRefundController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $receipts = CustomerReceipt::with('customer')->where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('customer_receipts.*')
            ->selectSub(function ($q) {
                $q->from('customer_receipt_allocations')->whereColumn('customer_receipt_allocations.customer_receipt_id', 'customer_receipts.id')
                    ->selectRaw('COALESCE(SUM(customer_receipt_allocations.amount), 0)');
            }, 'allocated_amount')
            ->selectSub(function ($q) {
                $q->from('customer_refunds')->whereColumn('customer_refunds.customer_receipt_id', 'customer_receipts.id')
                    ->whereIn('customer_refunds.status', ['draft', 'posted'])
                    ->selectRaw('COALESCE(SUM(customer_refunds.amount), 0)');
            }, 'refunded_amount')
            ->orderByDesc('receipt_date')->get()
            ->filter(fn ($receipt) => round((float) $receipt->amount - (float) $receipt->allocated_amount - (float) $receipt->refunded_amount, 2) > 0);
        $refunds = CustomerRefund::with(['customer', 'receipt', 'bankAccount'])->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->latest('refund_date')->limit(40)->get();
        return view('finance.refunds.index', [
            'receipts' => $receipts,
            'refunds' => $refunds,
            'bankAccounts' => BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'customers' => Customer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'branches' => DB::table('branches')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'receipt_id' => ['required', 'integer', Rule::exists('customer_receipts', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'refund_number' => ['required', 'string', 'max:80', Rule::unique('customer_refunds')->where(fn ($q) => $q->where('company_id', $companyId))],
            'refund_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $requestedBranch = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        abort_if($branchId !== null && $requestedBranch !== null && $branchId !== $requestedBranch, 403);

        DB::transaction(function () use ($data, $companyId, $branchId, $requestedBranch, $request) {
            $receipt = CustomerReceipt::where('company_id', $companyId)->where('status', 'posted')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->lockForUpdate()->findOrFail($data['receipt_id']);
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->lockForUpdate()->findOrFail($data['bank_account_id']);
            $refundBranch = $branchId ?? $requestedBranch ?? $bank->branch_id;
            if ($bank->branch_id !== $refundBranch || $receipt->branch_id !== $refundBranch) {
                throw ValidationException::withMessages(['bank_account_id' => 'Refund, receipt and bank account must belong to the same branch.']);
            }
            if ($data['refund_date'] < $receipt->receipt_date->toDateString()) {
                throw ValidationException::withMessages(['refund_date' => 'Refund date cannot be earlier than the original receipt date.']);
            }
            if ($bank->currency !== 'PGK' || $receipt->currency !== 'PGK') {
                throw ValidationException::withMessages(['amount' => 'Customer refunds currently support PGK receipts and bank accounts only.']);
            }
            $allocated = (float) DB::table('customer_receipt_allocations')->where('customer_receipt_id', $receipt->id)->sum('amount');
            $reserved = (float) CustomerRefund::where('company_id', $companyId)->where('customer_receipt_id', $receipt->id)
                ->whereIn('status', ['draft', 'posted'])->sum('amount');
            $available = round((float) $receipt->amount - $allocated - $reserved, 2);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $available + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the unallocated customer deposit available on this receipt.']);
            }
            CustomerRefund::create([
                'company_id' => $companyId, 'branch_id' => $refundBranch,
                'customer_id' => $receipt->customer_id, 'customer_receipt_id' => $receipt->id,
                'bank_account_id' => $bank->id, 'refund_number' => $data['refund_number'],
                'refund_date' => $data['refund_date'], 'reference' => $data['reference'] ?? null,
                'description' => $data['description'], 'amount' => $amount, 'currency' => 'PGK',
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
        });
        return back()->with('status', 'Customer refund saved as a draft. A different finance user must post it.');
    }

    public function cancel(Request $request, int $refund)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($refund, $companyId, $branchId) {
            $document = CustomerRefund::where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($refund);
            abort_unless($document->status === 'draft', 409, 'Only draft customer refunds can be cancelled.');
            $document->update(['status' => 'cancelled']);
        });
        return back()->with('status', 'Draft customer refund cancelled; its deposit balance has been released.');
    }

    public function post(Request $request, int $refund)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($refund, $companyId, $branchId, $request) {
            $document = CustomerRefund::where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($refund);
            abort_unless($document->status === 'draft', 409, 'Only draft customer refunds can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The refund creator cannot post their own refund.');
            $receipt = CustomerReceipt::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->customer_receipt_id);
            abort_unless($receipt->status === 'posted' && (int) $receipt->customer_id === (int) $document->customer_id, 409, 'Refund must reference a posted receipt for the same customer.');
            abort_unless($document->refund_date->toDateString() >= $receipt->receipt_date->toDateString(), 422, 'Refund date cannot be earlier than the original receipt date.');
            abort_unless((int) ($receipt->branch_id ?? 0) === (int) ($document->branch_id ?? 0), 409, 'Refund and receipt branch attribution must match.');
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)->where('branch_id', $document->branch_id)
                ->lockForUpdate()->findOrFail($document->bank_account_id);
            abort_unless($bank->currency === 'PGK' && $receipt->currency === 'PGK', 422, 'Customer refunds currently support PGK only.');
            $allocated = (float) DB::table('customer_receipt_allocations')->where('customer_receipt_id', $receipt->id)->sum('amount');
            $otherRefunds = (float) CustomerRefund::where('company_id', $companyId)->where('customer_receipt_id', $receipt->id)
                ->whereIn('status', ['draft', 'posted'])->where('id', '!=', $document->id)->sum('amount');
            if ((float) $document->amount > round((float) $receipt->amount - $allocated - $otherRefunds, 2) + 0.009) {
                throw ValidationException::withMessages(['refund' => 'Posting would refund more than the unallocated customer deposit.']);
            }
            $deposits = Account::where('company_id', $companyId)->where('account_code', '2500')->firstOrFail();
            $bankLedger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            abort_unless($deposits->account_type === 'liability' && $deposits->normal_balance === 'credit', 422, 'Customer deposits control account 2500 must be a credit-normal liability.');
            abort_unless($bankLedger->account_type === 'asset' && $bankLedger->normal_balance === 'debit', 422, 'Bank ledger must be a debit-normal asset.');
            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->refund_date, 'source' => 'customer_refund', 'status' => 'posted',
                'description' => 'Customer refund '.$document->refund_number.' · '.$document->description,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $deposits->id, 'description' => 'Refund of customer deposit', 'debit' => $document->amount, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $bankLedger->id, 'description' => $document->description, 'debit' => 0, 'credit' => $document->amount]);
            BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id, 'bank_account_id' => $bank->id,
                'journal_entry_id' => $journal->id, 'transaction_date' => $document->refund_date,
                'reference' => $document->reference, 'description' => 'Customer refund '.$document->refund_number.' · '.$document->description,
                'direction' => 'out', 'amount' => $document->amount, 'is_reconciled' => false, 'created_by' => $document->created_by,
            ]);
            $document->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'posted_by' => $request->user()->id, 'posted_at' => now()]);
        });
        return back()->with('status', 'Customer refund posted against customer deposits and the bank ledger.');
    }
}
