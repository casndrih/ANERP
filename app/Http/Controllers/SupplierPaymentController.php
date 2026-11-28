<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierGstWithholding;
use App\Support\CompanyAuthorizer;
use App\Services\Section65AService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierPaymentController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        $bills = SupplierBill::with('supplier')->where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('supplier_bills.*')
            ->selectSub(function ($q) {
                $q->from('supplier_payment_allocations')
                    ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                    ->whereColumn('supplier_payment_allocations.supplier_bill_id', 'supplier_bills.id')
                    ->whereIn('supplier_payments.status', ['draft', 'posted'])
                    ->whereColumn('supplier_payments.company_id', 'supplier_bills.company_id')
                    ->selectRaw('COALESCE(SUM(supplier_payment_allocations.amount), 0)');
            }, 'amount_paid')
            ->selectSub(function ($q) {
                $q->from('supplier_credit_notes')->whereColumn('supplier_credit_notes.supplier_bill_id', 'supplier_bills.id')
                    ->whereIn('supplier_credit_notes.status', ['draft', 'posted'])
                    ->whereColumn('supplier_credit_notes.company_id', 'supplier_bills.company_id')
                    ->selectRaw('COALESCE(SUM(supplier_credit_notes.amount), 0)');
            }, 'amount_credited')
            ->selectSub(function ($q) {
                $q->from('supplier_stock_returns')->whereColumn('supplier_stock_returns.supplier_bill_id', 'supplier_bills.id')
                    ->whereIn('supplier_stock_returns.status', ['draft', 'posted'])
                    ->whereColumn('supplier_stock_returns.company_id', 'supplier_bills.company_id')
                    ->selectRaw('COALESCE(SUM(supplier_stock_returns.total_amount), 0)');
            }, 'amount_stock_returned')
            ->orderBy('due_date')->get()
            ->filter(fn ($bill) => round((float) $bill->amount - (float) $bill->amount_paid - (float) $bill->amount_credited - (float) $bill->amount_stock_returned, 2) > 0);

        $payments = SupplierPayment::with(['supplier', 'bankAccount', 'allocations.bill'])
            ->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('payment_date')->limit(40)->get();

        return view('finance.payments.index', [
            'suppliers' => Supplier::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'bills' => $bills,
            'payments' => $payments,
            'branches' => DB::table('branches')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request, Section65AService $section65A)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'payment_number' => ['required', 'string', 'max:80', Rule::unique('supplier_payments')->where(fn ($q) => $q->where('company_id', $companyId))],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.bill_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $assignedBranch = CompanyAuthorizer::activeBranchId($request);
        $requestedBranch = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        abort_if($assignedBranch !== null && $requestedBranch !== null && $assignedBranch !== $requestedBranch, 403);

        DB::transaction(function () use ($data, $companyId, $request, $assignedBranch, $requestedBranch, $section65A) {
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($assignedBranch, fn ($q) => $q->where('branch_id', $assignedBranch))
                ->lockForUpdate()->findOrFail($data['bank_account_id']);
            $branchId = $assignedBranch ?? $requestedBranch ?? $bank->branch_id;
            if ($bank->currency !== 'PGK') throw ValidationException::withMessages(['bank_account_id' => 'Supplier payments currently support PGK bank accounts only.']);
            if ($bank->branch_id !== $branchId) {
                throw ValidationException::withMessages(['bank_account_id' => 'The bank account must belong to the payment branch.']);
            }

            $payment = SupplierPayment::create([
                'company_id' => $companyId, 'branch_id' => $branchId,
                'supplier_id' => $data['supplier_id'], 'bank_account_id' => $bank->id,
                'payment_number' => $data['payment_number'], 'payment_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? null, 'description' => $data['description'],
                'amount' => round((float) $data['amount'], 2), 'cash_amount' => 0, 'withheld_amount' => 0, 'currency' => 'PGK',
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);

            $total = 0.0;
            $cashTotal = 0.0;
            $withheldTotal = 0.0;
            foreach ($data['allocations'] as $row) {
                $bill = SupplierBill::where('company_id', $companyId)->where('supplier_id', $data['supplier_id'])
                    ->where('status', 'posted')
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
                    ->lockForUpdate()->findOrFail($row['bill_id']);
                if ($bill->currency !== 'PGK') throw ValidationException::withMessages(['allocations' => 'Supplier bills must be in PGK until multi-currency settlement is implemented.']);
                $amount = round((float) $row['amount'], 2);
                $alreadyPaid = (float) DB::table('supplier_payment_allocations')
                    ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                    ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)
                    ->where('supplier_payments.company_id', $companyId)
                    ->whereIn('supplier_payments.status', ['draft', 'posted'])
                    ->sum('supplier_payment_allocations.amount');
                $alreadyCredited = (float) SupplierCreditNote::where('company_id', $companyId)->where('supplier_bill_id', $bill->id)
                    ->whereIn('status', ['draft', 'posted'])->sum('amount');
                $alreadyStockReturned = (float) DB::table('supplier_stock_returns')->where('company_id', $companyId)
                    ->where('supplier_bill_id', $bill->id)->whereIn('status', ['draft', 'posted'])->sum('total_amount');
                $outstanding = round((float) $bill->amount - $alreadyPaid - $alreadyCredited - $alreadyStockReturned, 2);
                if ($amount > $outstanding + 0.009) {
                    throw ValidationException::withMessages(['allocations' => 'Allocation exceeds the supplier bill outstanding balance.']);
                }

                $supplier = Supplier::where('company_id', $companyId)->findOrFail($bill->supplier_id);
                $notice = $section65A->applicableNotice($companyId, (int) $supplier->id, $data['payment_date']);
                $withheld = 0.0;
                if ($notice) {
                    $section65A->assertCompanyRegistered($companyId);
                    if ((float) $bill->gst_amount > 0 && (!(bool) $bill->supplier_gst_registered
                        || !filled(trim((string) $bill->supplier_gst_registration_number))
                        || !filled(trim((string) $supplier->tin))
                        || !filled(trim((string) $bill->supply_province)))) {
                        throw ValidationException::withMessages(['allocations' => 'A Section 65A-covered GST bill requires the supplier TIN, GST registration number and province where the supply was made before payment.']);
                    }
                    $alreadyWithheld = (float) DB::table('supplier_payment_allocations')
                        ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                        ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)
                        ->where('supplier_payments.company_id', $companyId)
                        ->whereIn('supplier_payments.status', ['draft', 'posted'])
                        ->sum('supplier_payment_allocations.withheld_amount');
                    $withheld = $section65A->withholdingAmount($notice, $bill, $amount, $alreadyWithheld);
                }
                $cash = round($amount - $withheld, 2);
                $total += $amount;
                $cashTotal += $cash;
                $withheldTotal += $withheld;
                SupplierPaymentAllocation::create([
                    'supplier_payment_id' => $payment->id, 'supplier_bill_id' => $bill->id,
                    'section65a_notice_id' => $notice?->id, 'amount' => $amount,
                    'cash_amount' => $cash, 'withheld_amount' => $withheld,
                ]);
            }
            if (abs(round($total, 2) - (float) $payment->amount) > 0.009) {
                throw ValidationException::withMessages(['allocations' => 'Allocate the full gross settlement amount to posted supplier bills. Unallocated supplier advances are not yet supported.']);
            }
            $payment->forceFill(['cash_amount' => round($cashTotal, 2), 'withheld_amount' => round($withheldTotal, 2)])->saveOrFail();
        });

        return back()->with('status', 'Supplier payment saved as a draft. A different finance user must post it.');
    }

    public function cancel(Request $request, int $payment)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($payment, $companyId, $branchId) {
            $document = SupplierPayment::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($payment);
            abort_unless($document->status === 'draft', 409, 'Only draft supplier payments can be cancelled.');
            $document->update(['status' => 'cancelled']);
        });
        return back()->with('status', 'Draft supplier payment cancelled; its bill allocations have been released.');
    }

    public function post(Request $request, int $payment, Section65AService $section65A)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($payment, $companyId, $branchId, $request, $section65A) {
            $document = SupplierPayment::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($payment);
            abort_unless($document->status === 'draft', 409, 'Only draft supplier payments can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The payment creator cannot post their own payment.');

            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $document->branch_id)->lockForUpdate()->findOrFail($document->bank_account_id);
            if ($bank->currency !== 'PGK') throw ValidationException::withMessages(['bank_account_id' => 'Supplier payments currently support PGK bank accounts only.']);
            $allocations = SupplierPaymentAllocation::where('supplier_payment_id', $document->id)->lockForUpdate()->get();
            $allocated = round((float) $allocations->sum('amount'), 2);
            if (abs($allocated - (float) $document->amount) > 0.009) {
                throw ValidationException::withMessages(['payment' => 'Supplier payment gross allocations must equal the payment total.']);
            }
            $cashTotal = 0.0;
            $withheldTotal = 0.0;

            foreach ($allocations as $allocation) {
                $bill = SupplierBill::where('company_id', $companyId)->lockForUpdate()->findOrFail($allocation->supplier_bill_id);
                abort_unless($bill->currency === 'PGK', 422, 'Supplier bills must be in PGK until multi-currency settlement is implemented.');
                abort_unless($bill->status === 'posted' && (int) $bill->supplier_id === (int) $document->supplier_id, 409, 'Payment allocations must reference posted bills for this supplier.');
                abort_unless((int) ($bill->branch_id ?? 0) === (int) ($document->branch_id ?? 0), 409, 'Payment and bill branch attribution must match.');
                $paid = (float) DB::table('supplier_payment_allocations')
                    ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                    ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)
                    ->whereIn('supplier_payments.status', ['draft', 'posted'])
                    ->where('supplier_payments.id', '!=', $document->id)
                    ->where('supplier_payments.company_id', $companyId)
                    ->sum('supplier_payment_allocations.amount');
                $credited = (float) SupplierCreditNote::where('company_id', $companyId)->where('supplier_bill_id', $bill->id)
                    ->whereIn('status', ['draft', 'posted'])->sum('amount');
                $stockReturned = (float) DB::table('supplier_stock_returns')->where('company_id', $companyId)
                    ->where('supplier_bill_id', $bill->id)->whereIn('status', ['draft', 'posted'])->sum('total_amount');
                if ((float) $allocation->amount > round((float) $bill->amount - $paid - $credited - $stockReturned, 2) + 0.009) {
                    throw ValidationException::withMessages(['allocations' => 'Payment would over-settle a supplier bill.']);
                }

                $supplier = Supplier::where('company_id', $companyId)->findOrFail($bill->supplier_id);
                $notice = $section65A->applicableNotice($companyId, (int) $supplier->id, $document->payment_date->toDateString());
                if ((int) ($allocation->section65a_notice_id ?? 0) !== (int) ($notice?->id ?? 0)) {
                    throw ValidationException::withMessages(['allocations' => 'The applicable Section 65A notice changed after this draft was created. Cancel and recreate the payment using the current notice.']);
                }
                $alreadyWithheld = (float) DB::table('supplier_payment_allocations')
                    ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
                    ->where('supplier_payment_allocations.supplier_bill_id', $bill->id)
                    ->where('supplier_payments.company_id', $companyId)
                    ->whereIn('supplier_payments.status', ['draft', 'posted'])
                    ->where('supplier_payments.id', '!=', $document->id)
                    ->sum('supplier_payment_allocations.withheld_amount');
                $expectedWithheld = $section65A->withholdingAmount($notice, $bill, (float) $allocation->amount, $alreadyWithheld);
                if (abs($expectedWithheld - (float) $allocation->withheld_amount) > 0.009) {
                    throw ValidationException::withMessages(['allocations' => 'Section 65A withholding changed since draft creation. Cancel and recreate the payment.']);
                }
                if ($notice && $expectedWithheld > 0) {
                    $section65A->assertCompanyRegistered($companyId);
                    abort_unless((bool) $bill->supplier_gst_registered
                        && filled(trim((string) $bill->supplier_gst_registration_number))
                        && filled(trim((string) $supplier->tin))
                        && filled(trim((string) $bill->supply_province)), 422,
                        'A Section 65A withholding requires supplier TIN, GST registration number and supply province.');
                    SupplierGstWithholding::create([
                        'company_id' => $companyId, 'branch_id' => $document->branch_id,
                        'notice_id' => $notice->id, 'supplier_payment_id' => $document->id,
                        'supplier_payment_allocation_id' => $allocation->id, 'supplier_bill_id' => $bill->id,
                        'supplier_id' => $supplier->id, 'supplier_name_snapshot' => $supplier->name,
                        'supplier_tin_snapshot' => $supplier->tin, 'supplier_gst_registration_snapshot' => $bill->supplier_gst_registration_number,
                        'supply_description' => $bill->description, 'supplier_province' => $bill->supply_province,
                        'bill_number_snapshot' => $bill->bill_number, 'bill_date' => $bill->bill_date,
                        'payment_date' => $document->payment_date, 'gross_settlement' => $allocation->amount,
                        'gst_withheld' => $allocation->withheld_amount, 'cash_paid' => $allocation->cash_amount,
                        'status' => 'unremitted',
                    ]);
                }
                $cashTotal += (float) $allocation->cash_amount;
                $withheldTotal += (float) $allocation->withheld_amount;
            }
            if (abs(round($cashTotal, 2) - (float) $document->cash_amount) > 0.009
                || abs(round($withheldTotal, 2) - (float) $document->withheld_amount) > 0.009
                || abs(round($cashTotal + $withheldTotal, 2) - (float) $document->amount) > 0.009) {
                throw ValidationException::withMessages(['payment' => 'Gross, cash and withheld amounts do not reconcile.']);
            }

            $ap = Account::where('company_id', $companyId)->where('account_code', '2000')->firstOrFail();
            $bankLedger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            abort_unless($ap->account_type === 'liability' && $ap->normal_balance === 'credit', 422, 'AP control account 2000 must be a credit-normal liability.');
            abort_unless($bankLedger->account_type === 'asset' && $bankLedger->normal_balance === 'debit', 422, 'Bank ledger must be a debit-normal asset.');

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->payment_date, 'source' => 'supplier_payment',
                'status' => 'posted', 'description' => 'Supplier payment '.$document->payment_number.' · '.$document->description,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ap->id, 'description' => 'Gross settlement of supplier bills', 'debit' => $document->amount, 'credit' => 0]);
            if ((float) $document->cash_amount > 0) {
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $bankLedger->id, 'description' => $document->description, 'debit' => 0, 'credit' => $document->cash_amount]);
            }
            if ((float) $document->withheld_amount > 0) {
                $withholdingLedger = Account::firstOrCreate(
                    ['company_id' => $companyId, 'account_code' => '2110'],
                    ['name' => 'GST Section 65A withholding payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]
                );
                abort_unless($withholdingLedger->account_type === 'liability' && $withholdingLedger->normal_balance === 'credit', 422, 'Section 65A withholding account 2110 must be a credit-normal liability.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $withholdingLedger->id, 'description' => 'GST withheld under Section 65A', 'debit' => 0, 'credit' => $document->withheld_amount]);
            }

            BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id, 'bank_account_id' => $bank->id,
                'journal_entry_id' => $journal->id, 'transaction_date' => $document->payment_date,
                'reference' => $document->reference, 'description' => 'Supplier payment '.$document->payment_number.' · '.$document->description,
                'direction' => 'out', 'amount' => $document->cash_amount, 'is_reconciled' => false, 'created_by' => $document->created_by,
            ]);
            $document->forceFill(['status' => 'posted', 'journal_entry_id' => $journal->id, 'posted_by' => $request->user()->id, 'posted_at' => now()])->saveOrFail();
        });

        return back()->with('status', 'Supplier payment posted to accounts payable and the bank ledger.');
    }
}
