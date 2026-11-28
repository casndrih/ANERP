<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerSalesReturn;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerCreditNoteController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $invoices = CustomerInvoice::with('customer')->where('company_id', $companyId)->where('status', 'posted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('customer_invoices.*')
            ->selectSub(function ($q) {
                $q->from('customer_receipt_allocations')->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                    ->whereColumn('customer_receipt_allocations.customer_invoice_id', 'customer_invoices.id')->whereIn('customer_receipts.status', ['draft', 'posted'])
                    ->whereColumn('customer_receipts.company_id', 'customer_invoices.company_id')->selectRaw('COALESCE(SUM(customer_receipt_allocations.amount), 0)');
            }, 'amount_paid')
            ->selectSub(function ($q) {
                $q->from('customer_credit_notes')->whereColumn('customer_credit_notes.customer_invoice_id', 'customer_invoices.id')
                    ->whereIn('customer_credit_notes.status', ['draft', 'posted'])->whereColumn('customer_credit_notes.company_id', 'customer_invoices.company_id')
                    ->selectRaw('COALESCE(SUM(customer_credit_notes.amount), 0)');
            }, 'amount_credited')
            ->selectSub(function ($q) {
                $q->from('customer_sales_returns')->whereColumn('customer_sales_returns.customer_invoice_id', 'customer_invoices.id')
                    ->where('customer_sales_returns.status', 'draft')->whereColumn('customer_sales_returns.company_id', 'customer_invoices.company_id')
                    ->selectRaw('COALESCE(SUM(customer_sales_returns.total_amount), 0)');
            }, 'amount_return_reserved')
            ->orderBy('due_date')->get()
            ->filter(fn ($invoice) => round((float) $invoice->amount - (float) $invoice->amount_paid - (float) $invoice->amount_credited - (float) $invoice->amount_return_reserved, 2) > 0);

        $notes = CustomerCreditNote::with(['customer', 'invoice'])->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->latest('credit_note_date')->limit(40)->get();

        return view('finance.credit-notes.index', compact('invoices', 'notes'));
    }

    public function store(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('customer_invoices', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))],
            'credit_note_number' => ['required', 'string', 'max:80', Rule::unique('customer_credit_notes')->where(fn ($q) => $q->where('company_id', $companyId))],
            'credit_note_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $invoice = CustomerInvoice::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($data['invoice_id']);
            if ($invoice->currency !== 'PGK') throw ValidationException::withMessages(['invoice_id' => 'Customer invoices must be in PGK until multi-currency settlement is implemented.']);
            abort_unless($invoice->status === 'posted', 409, 'Credit notes can only reference posted invoices.');
            if ($data['credit_note_date'] < $invoice->invoice_date->toDateString()) {
                throw ValidationException::withMessages(['credit_note_date' => 'Credit note date cannot be earlier than the original invoice date.']);
            }
            $paid = (float) DB::table('customer_receipt_allocations')->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                ->where('customer_receipt_allocations.customer_invoice_id', $invoice->id)->whereIn('customer_receipts.status', ['draft', 'posted'])
                ->where('customer_receipts.company_id', $companyId)->sum('customer_receipt_allocations.amount');
            $reservedCredits = (float) CustomerCreditNote::where('company_id', $companyId)->where('customer_invoice_id', $invoice->id)
                ->whereIn('status', ['draft', 'posted'])->sum('amount');
            $reservedReturns = (float) CustomerSalesReturn::where('company_id', $companyId)->where('customer_invoice_id', $invoice->id)
                ->where('status', 'draft')->sum('total_amount');
            $outstanding = round((float) $invoice->amount - $paid - $reservedCredits - $reservedReturns, 2);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Credit note exceeds the invoice outstanding balance after posted receipts and existing credit notes.']);
            }
            $rate = (float) ($invoice->gst_rate ?? 0);
            $netAmount = $rate > 0 ? round($amount / (1 + $rate / 100), 2) : $amount;
            $gstAmount = round($amount - $netAmount, 2);
            if ($netAmount > (float) ($invoice->net_amount ?? $invoice->amount) + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Credit note net value cannot exceed the original invoice net value.']);
            }
            CustomerCreditNote::create([
                'company_id' => $companyId, 'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id, 'customer_invoice_id' => $invoice->id,
                'credit_note_number' => $data['credit_note_number'], 'credit_note_date' => $data['credit_note_date'],
                'description' => $data['description'], 'amount' => $amount,
                'net_amount' => $netAmount, 'gst_amount' => $gstAmount,
                'gst_rate' => $rate, 'gst_treatment' => $invoice->gst_treatment ?? 'out_of_scope',
                'currency' => 'PGK', 'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('status', 'Customer credit note saved as a draft. A different finance user must post it.');
    }

    public function cancel(Request $request, int $creditNote)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        DB::transaction(function () use ($creditNote, $companyId, $branchId) {
            $note = CustomerCreditNote::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($creditNote);
            abort_unless($note->status === 'draft', 409, 'Only draft customer credit notes can be cancelled.');
            $note->update(['status' => 'cancelled']);
        });
        return back()->with('status', 'Draft customer credit note cancelled; its invoice balance has been released.');
    }

    public function post(Request $request, int $creditNote)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($creditNote, $companyId, $branchId, $request) {
            $note = CustomerCreditNote::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($creditNote);
            abort_unless($note->status === 'draft', 409, 'Only draft customer credit notes can be posted.');
            abort_if((int) $note->created_by === (int) $request->user()->id, 403, 'The credit note creator cannot post their own credit note.');

            $invoice = CustomerInvoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($note->customer_invoice_id);
            abort_unless($invoice->currency === 'PGK', 422, 'Customer invoices must be in PGK until multi-currency settlement is implemented.');
            abort_unless($invoice->status === 'posted' && (int) $invoice->customer_id === (int) $note->customer_id, 409, 'Credit note must reference an eligible posted invoice for the same customer.');
            abort_unless((int) ($invoice->branch_id ?? 0) === (int) ($note->branch_id ?? 0), 409, 'Credit note and invoice branch attribution must match.');

            $paid = (float) DB::table('customer_receipt_allocations')->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
                ->where('customer_receipt_allocations.customer_invoice_id', $invoice->id)->whereIn('customer_receipts.status', ['draft', 'posted'])
                ->where('customer_receipts.company_id', $companyId)->sum('customer_receipt_allocations.amount');
            $otherCredits = (float) CustomerCreditNote::where('company_id', $companyId)->where('customer_invoice_id', $invoice->id)
                ->whereIn('status', ['draft', 'posted'])->where('id', '!=', $note->id)->sum('amount');
            if ((float) $note->amount > round((float) $invoice->amount - $paid - $otherCredits, 2) + 0.009) {
                throw ValidationException::withMessages(['credit_note' => 'Posting would credit more than the invoice outstanding balance.']);
            }

            if (abs(round((float) $note->net_amount + (float) $note->gst_amount, 2) - (float) $note->amount) > 0.009) {
                throw ValidationException::withMessages(['credit_note' => 'Credit note net, GST and gross amounts do not reconcile.']);
            }
            if (abs((float) $note->gst_rate - (float) ($invoice->gst_rate ?? 0)) > 0.009 || ($note->gst_treatment ?? 'out_of_scope') !== ($invoice->gst_treatment ?? 'out_of_scope')) {
                throw ValidationException::withMessages(['credit_note' => 'Credit note GST treatment must match the original posted invoice.']);
            }
            $ar = Account::where('company_id', $companyId)->where('account_code', '1100')->firstOrFail();
            $revenue = Account::where('company_id', $companyId)->whereKey($invoice->revenue_account_id)->firstOrFail();
            abort_unless($ar->account_type === 'asset' && $ar->normal_balance === 'debit', 422, 'AR control account 1100 must be a debit-normal asset.');
            abort_unless($revenue->account_type === 'income' && $revenue->normal_balance === 'credit', 422, 'Invoice revenue account must be a credit-normal income account.');

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $note->branch_id,
                'journal_number' => 'JV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $note->credit_note_date, 'source' => 'customer_credit_note', 'status' => 'posted',
                'description' => 'Customer credit note '.$note->credit_note_number.' · '.$note->description,
                'created_by' => $note->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $revenue->id, 'description' => 'Sales credit note '.$note->credit_note_number, 'debit' => $note->net_amount, 'credit' => 0]);
            if ((float) $note->gst_amount > 0) {
                $outputTax = Account::where('company_id', $companyId)->where('account_code', '2100')->firstOrFail();
                abort_unless($outputTax->account_type === 'liability' && $outputTax->normal_balance === 'credit', 422, 'GST output account 2100 must be a credit-normal liability.');
                JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $outputTax->id, 'description' => 'Reverse output GST on credit note '.$note->credit_note_number, 'debit' => $note->gst_amount, 'credit' => 0]);
            }
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ar->id, 'description' => 'Reduction of customer receivable', 'debit' => 0, 'credit' => $note->amount]);

            $note->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'posted_by' => $request->user()->id, 'posted_at' => now()]);
        });

        return back()->with('status', 'Customer credit note posted to revenue and accounts receivable.');
    }
}
