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
