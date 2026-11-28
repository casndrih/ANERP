<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierGstWithholding;
use App\Models\SupplierGstWithholdingNotice;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Section65AWithholdingTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_scoped_supplier_payment_splits_cash_and_gst_then_remits_with_supplier_listing(): void
    {
        [$company, $branch, $supplier, $bank, $bill, $creator, $approver, $ap, $bankLedger] = $this->fixture();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.store'), [
                'notice_number' => 'IRC-65A-2026-001',
                'issuing_authority' => 'Internal Revenue Commission',
                'notice_date' => '2026-10-20',
                'effective_from' => '2026-11-01',
                'supplier_scope' => 'specified_suppliers',
                'remittance_form_code' => 'G2',
                'document_reference' => 'IRC letter registry 65A/001',
                'supplier_ids' => [$supplier->id],
            ])->assertRedirect()->assertSessionHasNoErrors();
        $notice = SupplierGstWithholdingNotice::where('notice_number', 'IRC-65A-2026-001')->firstOrFail();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.activate', $notice->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $notice->refresh();
        self::assertSame('active', $notice->status);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.payments.store'), [
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id,
                'payment_number' => 'PAY-65A-001', 'payment_date' => '2026-11-28',
                'description' => 'Section 65A settlement', 'amount' => 100,
                'allocations' => [['bill_id' => $bill->id, 'amount' => 100]],
            ])->assertRedirect()->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('payment_number', 'PAY-65A-001')->firstOrFail();
        self::assertSame(90.91, (float) $payment->cash_amount);
        self::assertSame(9.09, (float) $payment->withheld_amount);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.payments.post', $payment->id))->assertRedirect()->assertSessionHasNoErrors();

        $withholding = SupplierGstWithholding::where('supplier_payment_id', $payment->id)->firstOrFail();
        self::assertSame(9.09, (float) $withholding->gst_withheld);
        self::assertSame('Morobe', $withholding->supplier_province);
        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '90.91',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $ap->id, 'debit' => '100.00', 'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $bankLedger->id, 'debit' => '0.00', 'credit' => '90.91',
        ]);
        $withholdingLiability = Account::where('company_id', $company->id)->where('account_code', '2110')->firstOrFail();
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $withholdingLiability->id, 'debit' => '0.00', 'credit' => '9.09',
        ]);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.remittances.store'), [
                'notice_id' => $notice->id, 'bank_account_id' => $bank->id,
                'remittance_number' => 'S65A-REMIT-001', 'form_code' => 'G2',
                'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
                'due_date' => '2026-12-21', 'remittance_date' => '2026-12-01',
                'reference' => 'IRC EFT 99881',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $remittance = DB::table('supplier_gst_remittances')->where('remittance_number', 'S65A-REMIT-001')->first();
        self::assertSame(9.09, (float) $remittance->amount);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.section65a.remittances.post', $remittance->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_gst_withholdings', [
            'id' => $withholding->id, 'status' => 'remitted', 'remittance_id' => $remittance->id,
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '9.09',
        ]);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.section65a.remittances.advice', $remittance->id), [
                'supplier_advice_reference' => 'SUPPLIER-ADVICE-2026-001',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_gst_withholdings', [
            'id' => $withholding->id, 'supplier_advice_reference' => 'SUPPLIER-ADVICE-2026-001',
        ]);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('finance.section65a.remittances.export', $remittance->id))
            ->assertDownload('ANERP-Section65A-S65A-REMIT-001.csv');
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('finance.gst-summary', ['from' => '2026-12-01', 'to' => '2026-12-01']))
            ->assertOk()->assertSee('Section 65A GST remitted to IRC')->assertSee('9.09');
    }

    public function test_overlapping_section_65a_notices_for_the_same_supplier_cannot_both_be_activated(): void
    {
        [$company, $branch, $supplier, $bank, $bill, $creator] = $this->fixture();
        $base = [
            'issuing_authority' => 'Internal Revenue Commission',
            'notice_date' => '2026-10-20', 'effective_from' => '2026-11-01',
            'supplier_scope' => 'specified_suppliers', 'remittance_form_code' => 'G2',
            'document_reference' => 'Written notice evidence', 'supplier_ids' => [$supplier->id],
        ];
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.store'), $base + ['notice_number' => 'IRC-65A-A'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $first = SupplierGstWithholdingNotice::where('notice_number', 'IRC-65A-A')->firstOrFail();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.activate', $first->id))->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.store'), $base + ['notice_number' => 'IRC-65A-B'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = SupplierGstWithholdingNotice::where('notice_number', 'IRC-65A-B')->firstOrFail();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.section65a.notices.activate', $second->id))
            ->assertSessionHasErrors('supplier_ids');
        $this->assertDatabaseHas('supplier_gst_withholding_notices', ['id' => $second->id, 'status' => 'draft']);
    }

    private function fixture(): array
    {
        $company = Company::create([
            'name' => 'Section 65A Co', 'is_active' => true,
            'gst_registered' => true, 'gst_registration_number' => 'GST-CO-001',
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 's65a-creator');
        $approver = $this->owner($company, 's65a-approver');
        $ap = Account::create(['company_id' => $company->id, 'account_code' => '2000', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $expense = Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Operating expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash at bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bankLedger->id, 'code' => 'BANK-S65A', 'name' => 'Section 65A Bank', 'currency' => 'PGK', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-S65A', 'name' => 'Registered Supplier Ltd', 'tin' => '123456789', 'is_active' => true]);
        $bill = SupplierBill::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'expense_account_id' => $expense->id, 'bill_number' => 'BILL-S65A-001',
            'bill_date' => '2026-11-01', 'due_date' => '2026-11-30', 'amount' => 100,
            'net_amount' => 90.91, 'gst_treatment' => 'standard', 'gst_rate' => 10, 'gst_amount' => 9.09,
            'supplier_gst_registered' => true, 'supplier_gst_registration_number' => 'GST-SUP-001',
            'tax_invoice_reference' => 'TAX-INV-001', 'input_tax_credit_eligible' => true,
            'supply_province' => 'Morobe',
            'currency' => 'PGK', 'description' => 'Office supplies', 'status' => 'posted',
            'created_by' => $creator->id, 'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        return [$company, $branch, $supplier, $bank, $bill, $creator, $approver, $ap, $bankLedger];
    }

    private function owner(Company $company, string $label): User
    {
        $user = User::create(['name' => $label, 'email' => $label.'-'.uniqid().'@example.test', 'password' => 'password']);
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'encrypted-test-secret'])->save();
        $user->companies()->attach($company->id, ['role_code' => 'owner', 'is_active' => true]);
        return $user;
    }

    private function sessionData(Company $company, User $user): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
