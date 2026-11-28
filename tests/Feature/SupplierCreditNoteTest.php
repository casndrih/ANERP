<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupplierCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_credit_note_reduces_ap_and_original_expense_account(): void
    {
        [$company, $branch, $supplier, $bill, $creator, $approver, $ap, $expense] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-001',
                'credit_note_date' => '2026-11-28', 'description' => 'Price adjustment', 'amount' => 30,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\SupplierCreditNote::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.supplier-credit-notes.post', $note->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_credit_notes', ['id' => $note->id, 'status' => 'posted']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ap->id, 'debit' => 30, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $expense->id, 'debit' => 0, 'credit' => 30]);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('reports.index', ['report' => 'payables', 'to' => '2026-11-28']))->assertOk()->assertSee('70.00');
    }

    public function test_gst_bearing_supplier_credit_note_reverses_input_tax_and_g1_summary(): void
    {
        [$company, $branch, $supplier, $bill, $creator, $approver, $ap, $expense] = $this->fixture();
        $company->update(['gst_registered' => true]);
        $inputTax = Account::create(['company_id' => $company->id, 'account_code' => '1300', 'name' => 'GST input tax', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bill->update([
            'amount' => 110, 'net_amount' => 100, 'gst_treatment' => 'standard', 'gst_rate' => 10,
            'gst_amount' => 10, 'supplier_gst_registered' => true,
            'supplier_gst_registration_number' => 'GST-SUP-1', 'tax_invoice_reference' => 'TAX-SUP-1',
            'input_tax_credit_eligible' => true,
        ]);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-GST-001',
                'credit_note_date' => '2026-11-28', 'description' => 'Supplier GST price adjustment', 'amount' => 55,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\SupplierCreditNote::where('credit_note_number', 'SCN-GST-001')->firstOrFail();
        self::assertSame(50.0, (float) $note->net_amount);
        self::assertSame(5.0, (float) $note->gst_amount);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.supplier-credit-notes.post', $note->id))->assertRedirect()->assertSessionHasNoErrors();
        $note->refresh();
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ap->id, 'debit' => '55.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $expense->id, 'debit' => '0.00', 'credit' => '50.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $inputTax->id, 'debit' => '0.00', 'credit' => '5.00']);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('finance.gst-summary', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->assertSee('K 50.00')->assertSee('K 5.00');
    }

    public function test_supplier_credit_note_cannot_exceed_bill_and_rejects_inventory_linked_bills(): void
    {
        [$company, $branch, $supplier, $bill, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-OVER',
                'credit_note_date' => '2026-11-28', 'description' => 'Over credit', 'amount' => 101,
            ])->assertSessionHasErrors('amount');
        $bill->update(['match_status' => 'approved']);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-STOCK',
                'credit_note_date' => '2026-11-28', 'description' => 'Inventory credit', 'amount' => 20,
            ])->assertSessionHasErrors('bill_id');
    }

    public function test_cancelled_draft_releases_supplier_bill_credit_balance(): void
    {
        [$company, $branch, $supplier, $bill, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-CANCEL-1',
                'credit_note_date' => '2026-11-28', 'description' => 'Cancelled draft', 'amount' => 40,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\SupplierCreditNote::where('credit_note_number', 'SCN-CANCEL-1')->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.supplier-credit-notes.cancel', $note->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_credit_notes', ['id' => $note->id, 'status' => 'cancelled']);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-CANCEL-2',
                'credit_note_date' => '2026-11-28', 'description' => 'Replacement draft', 'amount' => 100,
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_draft_supplier_credit_note_reserves_bill_balance_against_payment(): void
    {
        [$company, $branch, $supplier, $bill, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.supplier-credit-notes.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SCN-RESERVE',
                'credit_note_date' => '2026-11-28', 'description' => 'Reserved supplier credit', 'amount' => 60,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash at bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bank = \App\Models\BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bankLedger->id, 'code' => 'BANK-1', 'name' => 'Main Bank', 'currency' => 'PGK', 'is_active' => true]);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.payments.store'), [
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id,
                'payment_number' => 'PAY-RESERVE', 'payment_date' => '2026-11-28',
                'description' => 'Payment against credited bill', 'amount' => 50,
                'allocations' => [['bill_id' => $bill->id, 'amount' => 50]],
            ])->assertSessionHasErrors('allocations');
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Supplier Credits Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 'supplier-credit-creator'); $approver = $this->owner($company, 'supplier-credit-approver');
        $ap = Account::create(['company_id' => $company->id, 'account_code' => '2000', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $expense = Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Operating expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-1', 'name' => 'Test Supplier', 'is_active' => true]);
        $bill = SupplierBill::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'expense_account_id' => $expense->id, 'bill_number' => 'BILL-'.uniqid(),
            'bill_date' => '2026-11-01', 'due_date' => '2026-11-30', 'amount' => 100, 'currency' => 'PGK',
            'description' => 'General supplier bill', 'invoice_type' => 'general', 'match_status' => 'not_required',
            'status' => 'posted', 'created_by' => $creator->id, 'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        return [$company, $branch, $supplier, $bill, $creator, $approver, $ap, $expense];
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
