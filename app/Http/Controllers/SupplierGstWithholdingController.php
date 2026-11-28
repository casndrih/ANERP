<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\SupplierGstRemittance;
use App\Models\SupplierGstWithholding;
use App\Models\SupplierGstWithholdingNotice;
use App\Services\Section65AService;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierGstWithholdingController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        return view('finance.section65a.index', [
            'notices' => SupplierGstWithholdingNotice::with(['suppliers'])
                ->where('company_id', $companyId)->latest('effective_from')->limit(50)->get(),
            'suppliers' => Supplier::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('currency', 'PGK')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('name')->get(),
            'withholdings' => SupplierGstWithholding::with(['notice', 'supplier', 'bill', 'payment', 'remittance'])
                ->where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->latest('payment_date')->limit(100)->get(),
            'remittances' => SupplierGstRemittance::with(['notice', 'bankAccount', 'withholdings'])
                ->where('company_id', $companyId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->latest('remittance_date')->limit(50)->get(),
            'pendingTotal' => (float) SupplierGstWithholding::where('company_id', $companyId)->where('status', 'unremitted')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('gst_withheld'),
            'assignedBranchId' => $branchId,
        ]);
    }

    public function storeNotice(Request $request, Section65AService $section65A)
    {
        CompanyAuthorizer::requirePermission($request, 'company.settings.manage');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $section65A->assertCompanyRegistered($companyId);
        $data = $request->validate([
            'notice_number' => ['required', 'string', 'max:100', Rule::unique('supplier_gst_withholding_notices')->where(fn ($q) => $q->where('company_id', $companyId))],
            'issuing_authority' => ['nullable', 'string', 'max:120'],
            'notice_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'supplier_scope' => ['required', Rule::in(['all_suppliers', 'specified_suppliers'])],
            'remittance_form_code' => ['required', Rule::in(['G2', 'G4', 'G5', 'G6'])],
            'document_reference' => ['nullable', 'string', 'max:255'],
            'notice_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'supplier_ids' => ['required_if:supplier_scope,specified_suppliers', 'array', 'min:1'],
            'supplier_ids.*' => ['integer', 'distinct', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
        ]);

        DB::transaction(function () use ($data, $companyId, $request) {
            $documentPath = $request->file('notice_document')?->store("section65a/notices/{$companyId}", 'local');
            $notice = SupplierGstWithholdingNotice::create([
                'company_id' => $companyId,
                'notice_number' => $data['notice_number'],
                'issuing_authority' => $data['issuing_authority'] ?? 'Internal Revenue Commission',
                'notice_date' => $data['notice_date'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'supplier_scope' => $data['supplier_scope'],
                'remittance_form_code' => $data['remittance_form_code'],
                'document_reference' => $documentPath ?? ($data['document_reference'] ?? null),
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['supplier_ids'] ?? [] as $supplierId) {
                $supplier = Supplier::where('company_id', $companyId)->findOrFail($supplierId);
                $notice->suppliers()->attach($supplier->id, [
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'supplier_tin_snapshot' => $supplier->tin,
                ]);
            }
        });

        return back()->with('status', 'Section 65A notice saved as a draft. Review the written notice and supplier scope before activating it.');
    }

    public function activate(Request $request, int $notice)
    {
        CompanyAuthorizer::requirePermission($request, 'company.settings.manage');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        DB::transaction(function () use ($notice, $companyId, $request) {
            $document = SupplierGstWithholdingNotice::where('company_id', $companyId)->lockForUpdate()->findOrFail($notice);
            abort_unless($document->status === 'draft', 409, 'Only draft Section 65A notices can be activated.');
            if ($document->supplier_scope === 'specified_suppliers' && !$document->suppliers()->exists()) {
                throw ValidationException::withMessages(['supplier_ids' => 'A specified-supplier notice must contain at least one supplier.']);
            }

            $newEnd = $document->effective_to?->toDateString() ?? '9999-12-31';
            $overlapping = SupplierGstWithholdingNotice::where('company_id', $companyId)->whereIn('status', ['active', 'revoked'])
                ->whereDate('effective_from', '<=', $newEnd)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $document->effective_from))
                ->where('id', '!=', $document->id)->get();

            foreach ($overlapping as $existing) {
                if ($document->supplier_scope === 'all_suppliers' || $existing->supplier_scope === 'all_suppliers') {
                    throw ValidationException::withMessages(['notice_number' => 'This notice overlaps another active all-supplier or supplier-specific notice. Resolve the overlap before activation.']);
                }
                $newIds = $document->suppliers()->pluck('suppliers.id')->all();
                $existingIds = $existing->suppliers()->pluck('suppliers.id')->all();
                if (array_intersect($newIds, $existingIds)) {
                    throw ValidationException::withMessages(['supplier_ids' => 'A supplier is already covered by another active Section 65A notice for an overlapping period.']);
                }
            }

            $document->update(['status' => 'active', 'activated_by' => $request->user()->id, 'activated_at' => now()]);
        });
        return back()->with('status', 'Section 65A notice activated for its recorded effective period and supplier scope.');
    }

    public function revoke(Request $request, int $notice)
    {
        CompanyAuthorizer::requirePermission($request, 'company.settings.manage');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
            'revocation_reference' => ['required', 'string', 'max:120'],
            'revocation_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        DB::transaction(function () use ($notice, $companyId, $data, $request) {
            $document = SupplierGstWithholdingNotice::where('company_id', $companyId)->lockForUpdate()->findOrFail($notice);
            abort_unless($document->status === 'active', 409, 'Only active Section 65A notices can be revoked.');
            abort_if($data['effective_to'] < $document->effective_from->toDateString(), 422, 'Revocation effective date cannot precede the notice effective date.');
            $laterPaymentExists = SupplierGstWithholding::where('company_id', $companyId)->where('notice_id', $document->id)
                ->whereDate('payment_date', '>', $data['effective_to'])->exists();
            abort_if($laterPaymentExists, 409, 'Withholdings exist after the proposed revocation date. Reconcile the notice effective period before revoking.');

            $documentPath = $request->file('revocation_document')?->store("section65a/revocations/{$companyId}", 'local');
            $document->update([
                'status' => 'revoked', 'effective_to' => $data['effective_to'],
                'revocation_reference' => $data['revocation_reference'],
                'revocation_document_reference' => $documentPath,
                'revoked_by' => $request->user()->id, 'revoked_at' => now(),
            ]);
        });
        return back()->with('status', 'Section 65A notice revoked from the recorded effective date. Earlier valid withholdings remain in the remittance register.');
    }

    public function storeRemittance(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);
        $data = $request->validate([
            'notice_id' => ['required', 'integer', Rule::exists('supplier_gst_withholding_notices', 'id')->where('company_id', $companyId)],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'remittance_number' => ['required', 'string', 'max:80', Rule::unique('supplier_gst_remittances')->where(fn ($q) => $q->where('company_id', $companyId))],
            'form_code' => ['required', Rule::in(['G2', 'G4', 'G5', 'G6'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'due_date' => ['required', 'date'],
            'remittance_date' => ['required', 'date', 'after_or_equal:period_end'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($data, $companyId, $branchId, $request) {
            $notice = SupplierGstWithholdingNotice::where('company_id', $companyId)->lockForUpdate()->findOrFail($data['notice_id']);
            abort_unless(in_array($notice->status, ['active', 'revoked'], true), 409, 'Only activated notices can be remitted.');
            abort_unless($notice->remittance_form_code === $data['form_code'], 422, 'Remittance form must match the form recorded on the written notice.');
            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->lockForUpdate()->findOrFail($data['bank_account_id']);
            abort_unless($bank->currency === 'PGK', 422, 'Section 65A remittances currently support PGK bank accounts only.');

            $reserved = DB::table('supplier_gst_remittance_lines')
                ->join('supplier_gst_remittances', 'supplier_gst_remittances.id', '=', 'supplier_gst_remittance_lines.remittance_id')
                ->where('supplier_gst_remittances.company_id', $companyId)
                ->whereIn('supplier_gst_remittances.status', ['draft', 'posted'])
                ->select('supplier_gst_remittance_lines.withholding_id');
            $withholdings = SupplierGstWithholding::where('company_id', $companyId)
                ->where('notice_id', $notice->id)->where('status', 'unremitted')->whereNull('remittance_id')
                ->whereBetween('payment_date', [$data['period_start'], $data['period_end']])
                ->whereNotIn('id', $reserved)->when($bank->branch_id, fn ($q) => $q->where('branch_id', $bank->branch_id), fn ($q) => $q->whereNull('branch_id'))
                ->lockForUpdate()->get();
            if ($withholdings->isEmpty()) {
                throw ValidationException::withMessages(['period_start' => 'No unremitted Section 65A withholding records are available for this notice and period.']);
            }
            $amount = round((float) $withholdings->sum('gst_withheld'), 2);
            $remittance = SupplierGstRemittance::create([
                'company_id' => $companyId, 'branch_id' => $bank->branch_id, 'notice_id' => $notice->id,
                'bank_account_id' => $bank->id, 'remittance_number' => $data['remittance_number'],
                'form_code' => $data['form_code'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'due_date' => $data['due_date'] ?? null, 'remittance_date' => $data['remittance_date'],
                'reference' => $data['reference'] ?? null, 'amount' => $amount, 'currency' => 'PGK',
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
            foreach ($withholdings as $withholding) {
                DB::table('supplier_gst_remittance_lines')->insert([
                    'remittance_id' => $remittance->id, 'withholding_id' => $withholding->id,
                    'amount' => $withholding->gst_withheld, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        return back()->with('status', 'Section 65A remittance draft created with the unremitted supplier listing for the selected notice and period.');
    }

    public function postRemittance(Request $request, int $remittance)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.post');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $branchId = CompanyAuthorizer::activeBranchId($request);

        DB::transaction(function () use ($remittance, $companyId, $branchId, $request) {
            $document = SupplierGstRemittance::where('company_id', $companyId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()->findOrFail($remittance);
            abort_unless($document->status === 'draft', 409, 'Only draft Section 65A remittances can be posted.');
            abort_if((int) $document->created_by === (int) $request->user()->id, 403, 'The remittance creator cannot post their own remittance.');

            $bank = BankAccount::where('company_id', $companyId)->where('is_active', true)
                ->where('branch_id', $document->branch_id)->lockForUpdate()->findOrFail($document->bank_account_id);
            abort_unless($bank->currency === 'PGK', 422, 'Section 65A remittances currently support PGK only.');
            $lines = DB::table('supplier_gst_remittance_lines')->where('remittance_id', $document->id)->lockForUpdate()->get();
            abort_unless($lines->isNotEmpty(), 409, 'A remittance must contain at least one supplier withholding.');
            $withholdingIds = $lines->pluck('withholding_id')->all();
            $withholdings = SupplierGstWithholding::where('company_id', $companyId)->whereIn('id', $withholdingIds)->lockForUpdate()->get();
            abort_unless($withholdings->count() === $lines->count(), 409, 'One or more withholding records are no longer available.');
            $lineAmounts = $lines->keyBy('withholding_id');
            foreach ($withholdings as $withholding) {
                $line = $lineAmounts->get($withholding->id);
                abort_unless($withholding->status === 'unremitted' && $withholding->remittance_id === null
                    && (int) $withholding->notice_id === (int) $document->notice_id
                    && $line && abs((float) $line->amount - (float) $withholding->gst_withheld) <= 0.009,
                    409, 'A withholding record is already remitted, has changed or belongs to another notice.');
            }
            $amount = round((float) $lines->sum('amount'), 2);
            abort_unless(abs($amount - (float) $document->amount) <= 0.009, 409, 'Remittance total does not reconcile to its supplier listing.');

            $bankLedger = Account::where('company_id', $companyId)->whereKey($bank->account_id)->firstOrFail();
            $withholdingLedger = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => '2110'],
                ['name' => 'GST Section 65A withholding payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]
            );
            abort_unless($bankLedger->account_type === 'asset' && $bankLedger->normal_balance === 'debit', 422, 'Bank ledger must be a debit-normal asset.');
            abort_unless($withholdingLedger->account_type === 'liability' && $withholdingLedger->normal_balance === 'credit', 422, 'Section 65A withholding account 2110 must be a credit-normal liability.');

            $journal = JournalEntry::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id,
                'journal_number' => 'S65A-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'journal_date' => $document->remittance_date, 'source' => 'supplier_gst_remittance',
                'status' => 'posted', 'description' => 'Section 65A remittance '.$document->remittance_number,
                'created_by' => $document->created_by, 'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $withholdingLedger->id, 'description' => 'Remit GST withheld under Section 65A', 'debit' => $document->amount, 'credit' => 0]);
            JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $bankLedger->id, 'description' => 'Payment to IRC', 'debit' => 0, 'credit' => $document->amount]);

            $bankTransaction = BankTransaction::create([
                'company_id' => $companyId, 'branch_id' => $document->branch_id, 'bank_account_id' => $bank->id,
                'journal_entry_id' => $journal->id, 'transaction_date' => $document->remittance_date,
                'reference' => $document->reference ?? $document->remittance_number,
                'description' => 'Section 65A remittance '.$document->remittance_number,
                'direction' => 'out', 'amount' => $document->amount, 'is_reconciled' => false,
                'created_by' => $document->created_by,
            ]);
            SupplierGstWithholding::whereIn('id', $withholdingIds)->update([
                'status' => 'remitted', 'remittance_id' => $document->id, 'remitted_at' => now(), 'updated_at' => now(),
            ]);
            $document->update([
                'status' => 'posted', 'journal_entry_id' => $journal->id, 'bank_transaction_id' => $bankTransaction->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
        });
        return back()->with('status', 'Section 65A remittance posted to the bank ledger. Record supplier advice after notifying each supplier.');
    }

    public function cancelRemittance(Request $request, int $remittance)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        DB::transaction(function () use ($remittance, $companyId) {
            $document = SupplierGstRemittance::where('company_id', $companyId)->lockForUpdate()->findOrFail($remittance);
            abort_unless($document->status === 'draft', 409, 'Only draft remittances can be cancelled.');
            $document->update(['status' => 'cancelled']);
        });
        return back()->with('status', 'Section 65A remittance draft cancelled; its withholding records are available for a new batch.');
    }

    public function markSupplierAdvised(Request $request, int $remittance)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.create');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate(['supplier_advice_reference' => ['required', 'string', 'max:120']]);
        DB::transaction(function () use ($remittance, $companyId, $data) {
            $document = SupplierGstRemittance::where('company_id', $companyId)->lockForUpdate()->findOrFail($remittance);
            abort_unless($document->status === 'posted', 409, 'Supplier advice can only be recorded after the remittance is posted.');
            $ids = DB::table('supplier_gst_remittance_lines')->where('remittance_id', $document->id)->pluck('withholding_id');
            SupplierGstWithholding::whereIn('id', $ids)->update([
                'supplier_advised_at' => now(), 'supplier_advice_reference' => $data['supplier_advice_reference'], 'updated_at' => now(),
            ]);
        });
        return back()->with('status', 'Supplier withholding advice confirmation recorded. ANERP does not send the supplier notification automatically.');
    }

    public function exportSupplierListing(Request $request, int $remittance)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $document = SupplierGstRemittance::where('company_id', $companyId)->where('status', 'posted')->findOrFail($remittance);
        $rows = DB::table('supplier_gst_remittance_lines as lines')
            ->join('supplier_gst_withholdings as w', 'w.id', '=', 'lines.withholding_id')
            ->where('lines.remittance_id', $document->id)
            ->select('w.supplier_name_snapshot', 'w.supplier_tin_snapshot', 'w.supplier_gst_registration_snapshot',
                'w.bill_number_snapshot', 'w.bill_date', 'w.payment_date', 'w.supply_description',
                'w.gross_settlement', 'w.gst_withheld', 'w.supplier_province', 'w.supplier_advised_at', 'w.supplier_advice_reference')
            ->orderBy('w.supplier_name_snapshot')->get();
        $filename = 'ANERP-Section65A-'.$document->remittance_number.'.csv';
        return response()->streamDownload(function () use ($rows, $document) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Remittance number', 'Form code', 'Supplier name', 'Supplier TIN', 'Supplier GST registration number', 'Supplier invoice', 'Invoice date', 'Payment date', 'Supply description', 'Gross amount incurred', 'GST remitted for supplier', 'Province of supply', 'Supplier advised at', 'Supplier advice reference']);
            foreach ($rows as $row) {
                fputcsv($out, [$document->remittance_number, $document->form_code, $row->supplier_name_snapshot, $row->supplier_tin_snapshot, $row->supplier_gst_registration_snapshot,
                    $row->bill_number_snapshot, $row->bill_date, $row->payment_date, $row->supply_description,
                    $row->gross_settlement, $row->gst_withheld, $row->supplier_province, $row->supplier_advised_at, $row->supplier_advice_reference]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
