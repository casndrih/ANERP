<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerReceiptController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        $invoices = CustomerInvoice::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->select('customer_invoices.*')
            ->selectSub(function ($query) {
                $query->from('customer_receipt_allocations')
                    ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                    ->whereColumn('customer_receipt_allocations.customer_invoice_id', 'customer_invoices.id')
                    ->where('customer_receipts.status', 'posted')
                    ->whereColumn('customer_receipts.company_id', 'customer_invoices.company_id')
                    ->selectRaw('COALESCE(SUM(customer_receipt_allocations.amount), 0)');
            }, 'amount_paid')
            ->selectSub(function ($query) use ($companyId) {
                $query->from('customer_credit_notes')
                    ->whereColumn('customer_credit_notes.customer_invoice_id', 'customer_invoices.id')
                    ->whereIn('customer_credit_notes.status', ['draft', 'posted'])
                    ->where('customer_credit_notes.company_id', $companyId)
                    ->selectRaw('COALESCE(SUM(customer_credit_notes.amount), 0)');
            }, 'amount_credited')
            ->orderBy('due_date')
            ->get()
            ->filter(fn ($invoice) => round((float) $invoice->amount - (float) $invoice->amount_paid - (float) $invoice->amount_credited, 2) > 0);

        $receipts = CustomerReceipt::with(['customer', 'bankAccount', 'allocations.invoice'])
            ->where('company_id', $companyId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('receipt_date')->limit(40)->get();

        return view('finance.receipts.index', [
            'customers' => Customer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->orderBy('name')->get(),
            'invoices' => $invoices,
            'receipts' => $receipts,
            'branches' => DB::table('branches')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'receipt_number' => ['required', 'string', 'max:80', Rule::unique('customer_receipts')->where(fn ($query) => $query->where('company_id', $companyId))],
            'receipt_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'distinct'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ]);

        $assignedBranch = CompanyAuthorizer::activeBranchId($request);
        $requestedBranch = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($assignedBranch !== null && $requestedBranch !== null && $requestedBranch !== $assignedBranch) {
            abort(403, 'You cannot create a receipt for another branch.');
        }

        DB::transaction(function () use ($data, $companyId, $request, $assignedBranch, $requestedBranch) {
            $bank = BankAccount::where('company_id', $companyId)
                ->when($assignedBranch, fn ($query) => $query->where('branch_id', $assignedBranch))
                ->lockForUpdate()->findOrFail($data['bank_account_id']);
            $branchId = $assignedBranch ?? $requestedBranch ?? $bank->branch_id;
            if ($requestedBranch !== null && $bank->branch_id !== $requestedBranch) {
                throw ValidationException::withMessages(['bank_account_id' => 'The selected bank account must belong to the receipt branch.']);
            }
            if ($assignedBranch !== null && $bank->branch_id !== $assignedBranch) {
                throw ValidationException::withMessages(['bank_account_id' => 'The selected bank account is not assigned to your branch.']);
            }

            $receipt = CustomerReceipt::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'customer_id' => $data['customer_id'],
                'bank_account_id' => $bank->id,
                'receipt_number' => $data['receipt_number'],
                'receipt_date' => $data['receipt_date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'amount' => round((float) $data['amount'], 2),
                'currency' => 'PGK',
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $allocationTotal = 0.0;
            foreach ($data['allocations'] ?? [] as $allocation) {
                $amount = round((float) ($allocation['amount'] ?? 0), 2);
                if ($amount <= 0) { continue; }
                $invoice = CustomerInvoice::where('company_id', $companyId)
                    ->where('customer_id', $data['customer_id'])
                    ->where('status', 'posted')
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId), fn ($query) => $query->whereNull('branch_id'))
                    ->findOrFail($allocation['invoice_id']);
                $allocationTotal += $amount;
                CustomerReceiptAllocation::create([
                    'customer_receipt_id' => $receipt->id,
                    'customer_invoice_id' => $invoice->id,
                    'amount' => $amount,
                ]);
            }

            if (round($allocationTotal, 2) - (float) $receipt->amount > 0.009) {
                throw ValidationException::withMessages(['allocations' => 'Allocated amounts cannot exceed the receipt total.']);
            }
        });

        return back()->with('status', 'Customer receipt saved as a draft. Another finance user must post it.');
    }

    public function post(Request $request, int $receipt)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($receipt, $companyId, $branchId, $request) {
            $document = CustomerReceipt::where('company_id', $companyId)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($receipt);
            abort_unless($document->status === 'draft', 409, 'Only draft customer receipts can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The receipt creator cannot post their own receipt.');

            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($document->bank_account_id);
            $allocations = CustomerReceiptAllocation::where('customer_receipt_id', $document->id)->lockForUpdate()->get();
            $allocated = round((float) $allocations->sum('amount'), 2);
            if ($allocated - (float) $document->amount > 0.009) {
                throw ValidationException::withMessages(['receipt' => 'Allocated value exceeds the receipt total.']);
            }

            foreach ($allocations as $allocation) {
                $invoice = CustomerInvoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($allocation->customer_invoice_id);
                abort_unless($invoice->status === 'posted' && (int) $invoice->customer_id === (int) $document->customer_id, 409, 'A receipt can only be allocated to posted invoices belonging to the same customer.');
                if ((int) ($invoice->branch_id ?? 0) !== (int) ($document->branch_id ?? 0)) {
                    throw ValidationException::withMessages(['allocations' => 'Receipt and invoice branch attribution must match.']);
                }
                $alreadyPaid = (float) DB::table('customer_receipt_allocations')
                    ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                    ->where('customer_receipt_allocations.customer_invoice_id', $invoice->id)
                    ->where('customer_receipts.status', 'posted')
                    ->where('customer_receipts.id', '!=', $document->id)
                    ->where('customer_receipts.company_id', $companyId)
                    ->sum('customer_receipt_allocations.amount');
                $alreadyCredited = (float) CustomerCreditNote::where('company_id', $companyId)
                    ->where('customer_invoice_id', $invoice->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
                $outstanding = round((float) $invoice->amount - $alreadyPaid - $alreadyCredited, 2);
                if ((float) $allocation->amount > $outstanding + 0.009) {
                    throw ValidationException::withMessages(['allocations' => "Allocation exceeds the outstanding balance of invoice {$invoice->invoice_number} (K".number_format(max(0, $outstanding), 2).")."]);
                }
            }

            $ar = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => '1100'],
                ['name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]
            );
            if ($ar->account_type !== 'asset' || $ar->normal_balance !== 'debit') {
                throw ValidationException::withMessages(['receipt' => 'Chart account 1100 must be a debit-normal asset account.']);
            }
            $bankLedger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            if ($bankLedger->account_type !== 'asset' || $ar->account_type !== 'asset') {
                throw ValidationException::withMessages(['receipt' => 'The bank ledger and accounts receivable control accounts must both be asset accounts.']);
            }
            if ($bank->branch_id !== $document->branch_id) {
                throw ValidationException::withMessages(['receipt' => 'The receipt branch no longer matches the selected bank account.']);
            }
            $advances = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => '2500'],
                ['name' => 'Customer deposits and advances', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]
            );
            if ($advances->account_type !== 'liability' || $advances->normal_balance !== 'credit') {
                throw ValidationException::withMessages(['receipt' => 'Chart account 2500 must be a credit-normal liability account.']);
            }

            $unallocated = round((float) $document->amount - $allocated, 2);
            $journal = JournalEntry::create([
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->receipt_date,
                'source' => 'customer_receipt',
                'status' => 'posted',
                'description' => 'Customer receipt '.$document->receipt_number.' · '.$document->description,
                'created_by' => $document->created_by,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id, 'account_id' => $bankLedger->id,
                'description' => $document->description, 'debit' => $document->amount, 'credit' => 0,
            ]);
            if ($allocated > 0) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id, 'account_id' => $ar->id,
                    'description' => 'Settlement of customer invoices', 'debit' => 0, 'credit' => $allocated,
                ]);
            }
            if ($unallocated > 0) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id, 'account_id' => $advances->id,
                    'description' => 'Unallocated customer deposit', 'debit' => 0, 'credit' => $unallocated,
                ]);
            }
            BankTransaction::create([
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'bank_account_id' => $bank->id,
                'journal_entry_id' => $journal->id,
                'transaction_date' => $document->receipt_date,
                'reference' => $document->reference,
                'description' => 'Customer receipt '.$document->receipt_number.' · '.$document->description,
                'direction' => 'in',
                'amount' => $document->amount,
                'is_reconciled' => false,
                'created_by' => $document->created_by,
            ]);

            $document->update([
                'status' => 'posted',
                'journal_entry_id' => $journal->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
        });

        return back()->with('status', 'Customer receipt posted to the bank and accounts receivable ledger.');
    }
}
