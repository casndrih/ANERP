<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CustomerInvoice;
use App\Models\CustomerReceipt;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Warehouse;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosSessionController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $sessions = DB::table('pos_sessions')
            ->join('users as cashiers', 'cashiers.id', '=', 'pos_sessions.opened_by')
            ->join('branches', 'branches.id', '=', 'pos_sessions.branch_id')
            ->join('warehouses', 'warehouses.id', '=', 'pos_sessions.warehouse_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'pos_sessions.cash_bank_account_id')
            ->where('pos_sessions.company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('pos_sessions.branch_id', $branchId))
            ->select('pos_sessions.*', 'cashiers.name as cashier_name', 'branches.name as branch_name', 'warehouses.name as warehouse_name', 'bank_accounts.name as cash_account_name')
            ->orderByDesc('pos_sessions.opened_at')->limit(40)->get();
        $openSessions = $sessions->where('status', 'open')->count();

        return view('sales.pos.index', [
            'sessions' => $sessions,
            'openSessions' => $openSessions,
            'warehouses' => Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'invoices' => CustomerInvoice::with('customer')->where('company_id', $companyId)
                ->where('status', 'posted')->where('currency', 'PGK')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->latest('invoice_date')->limit(50)->get(),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function open(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'session_number' => ['required', 'string', 'max:80', Rule::unique('pos_sessions')->where(fn ($q) => $q->where('company_id', $companyId))],
            'opening_float' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $warehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['warehouse_id']);
            abort_if($warehouse->branch_id === null, 422, 'A POS till must be assigned to a branch warehouse.');
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $warehouse->branch_id)->lockForUpdate()->findOrFail($data['cash_bank_account_id']);
            if ($bank->currency !== 'PGK') throw ValidationException::withMessages(['cash_bank_account_id' => 'POS cash drawers currently support PGK only.']);
            $ledger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            if ($ledger->account_type !== 'asset' || $ledger->normal_balance !== 'debit') {
                throw ValidationException::withMessages(['cash_bank_account_id' => 'The till account must link to a debit-normal asset account.']);
            }
            $alreadyOpen = DB::table('pos_sessions')->where('company_id', $companyId)
                ->where('branch_id', $warehouse->branch_id)->where('opened_by', $request->user()->id)
                ->where('status', 'open')->lockForUpdate()->exists();
            if ($alreadyOpen) throw ValidationException::withMessages(['session_number' => 'This cashier already has an open till session at this branch.']);

            DB::table('pos_sessions')->insert([
                'company_id' => $companyId, 'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id, 'cash_bank_account_id' => $bank->id,
                'session_number' => $data['session_number'], 'status' => 'open',
                'opening_float' => round((float) $data['opening_float'], 2),
                'expected_cash' => round((float) $data['opening_float'], 2),
                'opened_by' => $request->user()->id, 'opened_at' => now(),
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'POS till session opened. Cash sales can now be received against posted customer invoices.');
    }

    public function sale(Request $request, int $session)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.fulfill');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('customer_invoices', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))],
            'transaction_number' => ['required', 'string', 'max:80', Rule::unique('pos_transactions')->where(fn ($q) => $q->where('company_id', $companyId))],
            'cash_tendered' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($data, $session, $companyId, $branchId, $request) {
            $till = DB::table('pos_sessions')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($session);
            abort_unless($till && $till->status === 'open' && (int) $till->opened_by === (int) $request->user()->id, 409, 'Only the cashier who opened an active till can process its sales.');

            $invoice = CustomerInvoice::where('company_id', $companyId)->where('status', 'posted')
                ->where('currency', 'PGK')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['invoice_id']);
            abort_unless((int) ($invoice->branch_id ?? 0) === (int) $till->branch_id, 409, 'Invoice and till must belong to the same branch.');
            $allocated = (float) DB::table('customer_receipt_allocations')
                ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                ->where('customer_receipt_allocations.customer_invoice_id', $invoice->id)
                ->where('customer_receipts.company_id', $companyId)
                ->whereIn('customer_receipts.status', ['draft', 'posted'])
                ->sum('customer_receipt_allocations.amount');
            $credits = (float) DB::table('customer_credit_notes')->where('company_id', $companyId)
                ->where('customer_invoice_id', $invoice->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $outstanding = round((float) $invoice->amount - $allocated - $credits, 2);
            if ($outstanding <= 0.009) throw ValidationException::withMessages(['invoice_id' => 'This invoice is already fully settled or credited.']);
            $tendered = round((float) $data['cash_tendered'], 2);
            if ($tendered + 0.009 < $outstanding) {
                throw ValidationException::withMessages(['cash_tendered' => 'Cash tendered is less than the outstanding invoice amount of K'.number_format($outstanding, 2).'.']);
            }
            $change = round($tendered - $outstanding, 2);
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $till->branch_id)->lockForUpdate()->findOrFail($till->cash_bank_account_id);
            abort_unless($bank->currency === 'PGK', 422, 'POS till account must use PGK.');
            $bankLedger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            $ar = Account::where('company_id', $companyId)->where('account_code', '1100')->firstOrFail();
            abort_unless($bankLedger->account_type === 'asset' && $bankLedger->normal_balance === 'debit', 422, 'Till ledger must be a debit-normal asset.');
            abort_unless($ar->account_type === 'asset' && $ar->normal_balance === 'debit', 422, 'AR control account 1100 must be a debit-normal asset.');

            $receiptNumber = 'POS-RCPT-'.Str::upper(Str::random(12));
            $receipt = CustomerReceipt::create([
                'company_id' => $companyId, 'branch_id' => $till->branch_id,
                'customer_id' => $invoice->customer_id, 'bank_account_id' => $bank->id,
                'receipt_number' => $receiptNumber, 'receipt_date' => now()->toDateString(),
                'reference' => $data['reference'] ?? $data['transaction_number'],
                'description' => 'POS cash sale '.$data['transaction_number'],
                'amount' => $outstanding, 'currency' => 'PGK', 'status' => 'posted',
                'created_by' => $request->user()->id, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            DB::table('customer_receipt_allocations')->insert([
                'customer_receipt_id' => $receipt->id, 'customer_invoice_id' => $invoice->id,
                'amount' => $outstanding, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $till->branch_id,
                'journal_number' => 'POS-JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => now()->toDateString(), 'source' => 'pos_cash_sale', 'status' => 'posted',
                'description' => 'POS receipt '.$receiptNumber, 'created_by' => $request->user()->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $bankLedger->id, 'description' => 'POS cash received', 'debit' => $outstanding, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ar->id, 'description' => 'Settle customer invoice '.$invoice->invoice_number, 'debit' => 0, 'credit' => $outstanding]);
            $receipt->update(['journal_entry_id' => $journal->id]);
            BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $till->branch_id, 'bank_account_id' => $bank->id,
                'journal_entry_id' => $journal->id, 'transaction_date' => now()->toDateString(),
                'reference' => $data['reference'] ?? $data['transaction_number'],
                'description' => 'POS cash sale '.$data['transaction_number'],
                'direction' => 'in', 'amount' => $outstanding, 'is_reconciled' => false,
                'created_by' => $request->user()->id,
            ]);

            DB::table('pos_transactions')->insert([
                'company_id' => $companyId, 'branch_id' => $till->branch_id,
                'pos_session_id' => $till->id, 'customer_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id, 'customer_receipt_id' => $receipt->id,
                'transaction_number' => $data['transaction_number'], 'amount' => $outstanding,
                'cash_tendered' => $tendered, 'change_amount' => $change,
                'payment_method' => 'cash', 'reference' => $data['reference'] ?? null,
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pos_sessions')->where('id', $till->id)->update([
                'expected_cash' => round((float) $till->opening_float + (float) DB::table('pos_transactions')->where('pos_session_id', $till->id)->sum('amount'), 2),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'POS cash sale recorded, customer invoice settled and bank receipt posted. Change is calculated automatically.');
    }

    public function close(Request $request, int $session)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.approve');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($session, $companyId, $branchId, $data, $request) {
            $till = DB::table('pos_sessions')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($session);
            abort_unless($till && $till->status === 'open', 409, 'Only open till sessions can be closed.');
            $expected = round((float) $till->opening_float + (float) DB::table('pos_transactions')->where('pos_session_id', $till->id)->sum('amount'), 2);
            $counted = round((float) $data['counted_cash'], 2);
            DB::table('pos_sessions')->where('id', $till->id)->update([
                'status' => 'closed', 'expected_cash' => $expected, 'counted_cash' => $counted,
                'cash_variance' => round($counted - $expected, 2),
                'closed_by' => $request->user()->id, 'closed_at' => now(),
                'notes' => $data['notes'] ?? $till->notes, 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'POS till closed. Expected cash and counted cash variance have been recorded for review.');
    }
}
