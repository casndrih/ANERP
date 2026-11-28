<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerReceipt;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosRefundController extends Controller
{
    public function storeRefund(Request $request, int $session)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'original_pos_transaction_id' => ['required', 'integer', Rule::exists('pos_transactions', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))],
            'customer_credit_note_id' => ['required', 'integer', Rule::exists('customer_credit_notes', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->whereNotNull('source_sales_return_id'))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $session, $companyId, $branchId, $request) {
            $till = DB::table('pos_sessions')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', 'open')->where('opened_by', $request->user()->id)
                ->lockForUpdate()->find($session);
            abort_unless($till, 409, 'Cash refunds must be created by the cashier who opened an active till in this branch.');

            $transaction = DB::table('pos_transactions')->where('company_id', $companyId)
                ->where('branch_id', $till->branch_id)->lockForUpdate()->find($data['original_pos_transaction_id']);
            abort_unless($transaction && $transaction->status === 'posted', 409, 'Refund must reference a posted POS cash sale.');

            $note = CustomerCreditNote::where('company_id', $companyId)->lockForUpdate()->findOrFail($data['customer_credit_note_id']);
            abort_unless($note->status === 'posted' && $note->source_sales_return_id, 409, 'Cash refunds require a posted item-level sales-return credit note.');
            abort_unless((int) $note->customer_invoice_id === (int) $transaction->customer_invoice_id
                && (int) $note->customer_id === (int) $transaction->customer_id
                && (int) $note->branch_id === (int) $till->branch_id, 409, 'Credit note and original POS sale must belong to the same customer, invoice and branch.');

            $reserved = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('customer_credit_note_id', $note->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $reservedForSale = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('original_pos_transaction_id', $transaction->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $available = min(
                round((float) $note->deposit_amount - $reserved, 2),
                round((float) $transaction->amount - $reservedForSale, 2)
            );
            if ((float) $data['amount'] > $available + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Cash refund exceeds the unrefunded customer deposit available on this credit note (K'.number_format(max(0, $available), 2).').']);
            }

            DB::table('pos_refunds')->insert([
                'company_id' => $companyId, 'branch_id' => $till->branch_id,
                'pos_session_id' => $till->id, 'original_pos_transaction_id' => $transaction->id,
                'customer_credit_note_id' => $note->id, 'customer_invoice_id' => $note->customer_invoice_id,
                'customer_id' => $note->customer_id, 'amount' => round((float) $data['amount'], 2),
                'reason' => $data['reason'], 'status' => 'draft',
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Cash refund saved as a draft against the posted sales-return credit note. A different finance user must post it.');
    }

    public function postRefund(Request $request, int $refund)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($refund, $companyId, $branchId, $request) {
            $document = DB::table('pos_refunds')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($refund);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft POS refunds can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The refund creator cannot post their own cash refund.');

            $till = DB::table('pos_sessions')->where('company_id', $companyId)->lockForUpdate()->find($document->pos_session_id);
            abort_unless($till && $till->status === 'open' && (int) $till->branch_id === (int) $document->branch_id, 409, 'The refund till must still be open in the original branch.');
            $transaction = DB::table('pos_transactions')->where('company_id', $companyId)
                ->lockForUpdate()->find($document->original_pos_transaction_id);
            abort_unless($transaction && $transaction->status === 'posted', 409, 'Original POS cash sale is no longer eligible for a refund.');
            $note = CustomerCreditNote::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->customer_credit_note_id);
            abort_unless($note->status === 'posted' && $note->source_sales_return_id
                && (int) $transaction->customer_invoice_id === (int) $note->customer_invoice_id, 409, 'Refund source credit note is not posted from a sales return for this POS invoice.');

            $reservedOther = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('customer_credit_note_id', $note->id)->whereIn('status', ['draft', 'posted'])
                ->where('id', '!=', $document->id)->sum('amount');
            $reservedForSale = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('original_pos_transaction_id', $transaction->id)->whereIn('status', ['draft', 'posted'])
                ->where('id', '!=', $document->id)->sum('amount');
            $available = min(
                round((float) $note->deposit_amount - $reservedOther, 2),
                round((float) $transaction->amount - $reservedForSale, 2)
            );
            if ((float) $document->amount > $available + 0.009) {
                throw ValidationException::withMessages(['refund' => 'The remaining customer deposit is no longer sufficient to post this refund.']);
            }

            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $till->branch_id)->lockForUpdate()->findOrFail($till->cash_bank_account_id);
            $cash = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            $deposits = Account::where('company_id', $companyId)->where('account_code', '2500')->lockForUpdate()->firstOrFail();
            abort_unless($bank->currency === 'PGK' && $cash->account_type === 'asset' && $cash->normal_balance === 'debit', 422, 'POS cash drawer must be a PGK debit-normal asset.');
            abort_unless($deposits->account_type === 'liability' && $deposits->normal_balance === 'credit', 422, 'Customer deposits control account 2500 must be a credit-normal liability.');

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'POS-REFUND-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => now()->toDateString(), 'source' => 'pos_cash_refund', 'status' => 'posted',
                'description' => 'POS cash refund · '.$document->reason,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $deposits->id, 'description' => 'Refund customer deposit from sales return', 'debit' => $document->amount, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $cash->id, 'description' => 'Cash refunded at POS till', 'debit' => 0, 'credit' => $document->amount]);

            $bankTransaction = BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'bank_account_id' => $bank->id, 'journal_entry_id' => $journal->id,
                'transaction_date' => now()->toDateString(), 'reference' => 'POS-REFUND-'.$document->id,
                'description' => 'POS cash refund · '.$document->reason,
                'direction' => 'out', 'amount' => $document->amount,
                'is_reconciled' => false, 'created_by' => $request->user()->id,
            ]);
            DB::table('pos_refunds')->where('id', $document->id)->update([
                'status' => 'posted', 'journal_entry_id' => $journal->id,
                'bank_transaction_id' => $bankTransaction->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(), 'updated_at' => now(),
            ]);
            $this->refreshExpectedCash($till->id);
        });

        return back()->with('status', 'POS cash refund posted. Customer deposits were debited and the till cash/bank transaction was reduced.');
    }

    public function cancelRefund(Request $request, int $refund)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        DB::transaction(function () use ($refund, $companyId, $request) {
            $document = DB::table('pos_refunds')->where('company_id', $companyId)->lockForUpdate()->find($refund);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft POS refunds can be cancelled.');
            abort_unless((int) $document->created_by === (int) $request->user()->id, 403, 'Only the refund creator can cancel this draft.');
            DB::table('pos_refunds')->where('id', $document->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
        return back()->with('status', 'POS refund draft cancelled and its customer deposit reservation released.');
    }

    public function storeVoid(Request $request, int $session)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'pos_transaction_id' => ['required', 'integer', Rule::exists('pos_transactions', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $session, $companyId, $branchId, $request) {
            $till = DB::table('pos_sessions')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', 'open')->where('opened_by', $request->user()->id)
                ->lockForUpdate()->find($session);
            abort_unless($till, 409, 'POS voids must be initiated from an open till owned by the current cashier.');

            $transaction = DB::table('pos_transactions')->where('company_id', $companyId)
                ->where('branch_id', $till->branch_id)->lockForUpdate()->find($data['pos_transaction_id']);
            abort_unless($transaction && $transaction->status === 'posted', 409, 'Only a posted POS cash transaction can be voided.');

            $receipt = CustomerReceipt::where('company_id', $companyId)->lockForUpdate()->findOrFail($transaction->customer_receipt_id);
            abort_unless($receipt->status === 'posted' && round((float) $receipt->amount, 2) === round((float) $transaction->amount, 2), 409, 'The original POS receipt must still be posted and match the POS transaction amount.');

            $allocations = DB::table('customer_receipt_allocations')->where('customer_receipt_id', $receipt->id)->get();
            abort_unless($allocations->count() === 1 && (int) $allocations->first()->customer_invoice_id === (int) $transaction->customer_invoice_id
                && round((float) $allocations->first()->amount, 2) === round((float) $transaction->amount, 2), 409, 'POS receipt allocation is not a single full settlement of the original invoice.');

            $otherReceipts = (float) DB::table('customer_receipt_allocations as a')
                ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
                ->where('a.customer_invoice_id', $transaction->customer_invoice_id)
                ->where('r.company_id', $companyId)->whereIn('r.status', ['draft', 'posted'])
                ->where('r.id', '!=', $receipt->id)->sum('a.amount');
            $credits = (float) CustomerCreditNote::where('company_id', $companyId)
                ->where('customer_invoice_id', $transaction->customer_invoice_id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $returns = (int) DB::table('customer_sales_returns')->where('company_id', $companyId)
                ->where('customer_invoice_id', $transaction->customer_invoice_id)->whereIn('status', ['draft', 'posted'])->count();
            $refunds = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('original_pos_transaction_id', $transaction->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            if ($otherReceipts > 0.009 || $credits > 0.009 || $returns > 0 || $refunds > 0.009) {
                throw ValidationException::withMessages(['pos_transaction_id' => 'This POS sale has subsequent settlements, credits, returns or refunds; it cannot be voided.']);
            }
            $existingVoid = DB::table('pos_voids')->where('pos_transaction_id', $transaction->id)
                ->whereIn('status', ['draft', 'posted'])->exists();
            if ($existingVoid) throw ValidationException::withMessages(['pos_transaction_id' => 'A void request already exists for this POS transaction.']);

            DB::table('pos_voids')->insert([
                'company_id' => $companyId, 'branch_id' => $till->branch_id,
                'pos_session_id' => $till->id, 'pos_transaction_id' => $transaction->id,
                'customer_receipt_id' => $receipt->id, 'customer_invoice_id' => $transaction->customer_invoice_id,
                'customer_id' => $transaction->customer_id, 'amount' => $transaction->amount,
                'reason' => $data['reason'], 'status' => 'draft',
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'POS void request saved. It will reverse the cash settlement and reopen the invoice only after independent approval.');
    }

    public function postVoid(Request $request, int $void)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($void, $companyId, $branchId, $request) {
            $document = DB::table('pos_voids')->where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->find($void);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft POS voids can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The void requester cannot post their own POS void.');

            $till = DB::table('pos_sessions')->where('company_id', $companyId)->lockForUpdate()->find($document->pos_session_id);
            abort_unless($till && $till->status === 'open' && (int) $till->branch_id === (int) $document->branch_id, 409, 'The cash-refund till must remain open in the original branch.');
            $transaction = DB::table('pos_transactions')->where('company_id', $companyId)->lockForUpdate()->find($document->pos_transaction_id);
            abort_unless($transaction && $transaction->status === 'posted', 409, 'The original POS transaction is no longer posted.');
            $receipt = CustomerReceipt::where('company_id', $companyId)->lockForUpdate()->findOrFail($document->customer_receipt_id);
            abort_unless($receipt->status === 'posted' && round((float) $receipt->amount, 2) === round((float) $document->amount, 2)
                && round((float) $transaction->amount, 2) === round((float) $document->amount, 2), 409, 'The original POS receipt and transaction no longer match the requested void amount.');
            $allocations = DB::table('customer_receipt_allocations')->where('customer_receipt_id', $receipt->id)->get();
            abort_unless($allocations->count() === 1 && (int) $allocations->first()->customer_invoice_id === (int) $document->customer_invoice_id
                && round((float) $allocations->first()->amount, 2) === round((float) $document->amount, 2), 409, 'The original receipt is no longer a single full invoice settlement.');

            $otherReceipts = (float) DB::table('customer_receipt_allocations as a')
                ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
                ->where('a.customer_invoice_id', $document->customer_invoice_id)
                ->where('r.company_id', $companyId)->whereIn('r.status', ['draft', 'posted'])
                ->where('r.id', '!=', $receipt->id)->sum('a.amount');
            $credits = (float) CustomerCreditNote::where('company_id', $companyId)
                ->where('customer_invoice_id', $document->customer_invoice_id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            $returns = (int) DB::table('customer_sales_returns')->where('company_id', $companyId)
                ->where('customer_invoice_id', $document->customer_invoice_id)->whereIn('status', ['draft', 'posted'])->count();
            $refunds = (float) DB::table('pos_refunds')->where('company_id', $companyId)
                ->where('original_pos_transaction_id', $transaction->id)->whereIn('status', ['draft', 'posted'])->sum('amount');
            abort_if($otherReceipts > 0.009 || $credits > 0.009 || $returns > 0 || $refunds > 0.009, 409, 'Subsequent customer activity prevents voiding this POS settlement.');

            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $till->branch_id)->lockForUpdate()->findOrFail($till->cash_bank_account_id);
            $cash = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            $ar = Account::where('company_id', $companyId)->where('account_code', '1100')->lockForUpdate()->firstOrFail();
            abort_unless($bank->currency === 'PGK' && $cash->account_type === 'asset' && $cash->normal_balance === 'debit', 422, 'POS cash drawer must be a PGK debit-normal asset.');
            abort_unless($ar->account_type === 'asset' && $ar->normal_balance === 'debit', 422, 'AR control account 1100 must be a debit-normal asset.');

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'POS-VOID-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => now()->toDateString(), 'source' => 'pos_cash_void', 'status' => 'posted',
                'description' => 'Void POS settlement · '.$document->reason,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ar->id, 'description' => 'Reopen customer invoice receivable', 'debit' => $document->amount, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $cash->id, 'description' => 'Return POS cash to customer', 'debit' => 0, 'credit' => $document->amount]);

            $bankTransaction = BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'bank_account_id' => $bank->id, 'journal_entry_id' => $journal->id,
                'transaction_date' => now()->toDateString(), 'reference' => 'POS-VOID-'.$document->id,
                'description' => 'POS void cash returned · '.$document->reason,
                'direction' => 'out', 'amount' => $document->amount,
                'is_reconciled' => false, 'created_by' => $request->user()->id,
            ]);
            $receipt->update(['status' => 'reversed']);
            DB::table('pos_transactions')->where('id', $transaction->id)->update(['status' => 'voided', 'voided_at' => now(), 'updated_at' => now()]);
            DB::table('pos_voids')->where('id', $document->id)->update([
                'status' => 'posted', 'journal_entry_id' => $journal->id,
                'bank_transaction_id' => $bankTransaction->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(), 'updated_at' => now(),
            ]);
            $this->refreshExpectedCash($till->id);
        });

        return back()->with('status', 'POS transaction voided. The cash settlement was reversed, the invoice receivable reopened and the cash outflow recorded.');
    }

    public function cancelVoid(Request $request, int $void)
    {
        CompanyAuthorizer::requirePermission($request, 'sales.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        DB::transaction(function () use ($void, $companyId, $request) {
            $document = DB::table('pos_voids')->where('company_id', $companyId)->lockForUpdate()->find($void);
            abort_unless($document && $document->status === 'draft', 409, 'Only draft POS voids can be cancelled.');
            abort_unless((int) $document->created_by === (int) $request->user()->id, 403, 'Only the void requester can cancel this draft.');
            DB::table('pos_voids')->where('id', $document->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
        return back()->with('status', 'POS void request cancelled.');
    }

    private function refreshExpectedCash(int $sessionId): void
    {
        $till = DB::table('pos_sessions')->lockForUpdate()->find($sessionId);
        if (!$till) return;
        $sales = (float) DB::table('pos_transactions')->where('pos_session_id', $sessionId)->sum('amount');
        $refunds = (float) DB::table('pos_refunds')->where('pos_session_id', $sessionId)->where('status', 'posted')->sum('amount');
        $voids = (float) DB::table('pos_voids')->where('pos_session_id', $sessionId)->where('status', 'posted')->sum('amount');
        DB::table('pos_sessions')->where('id', $sessionId)->update([
            'expected_cash' => round((float) $till->opening_float + $sales - $refunds - $voids, 2),
            'updated_at' => now(),
        ]);
    }
}
