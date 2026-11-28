<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_allocates_posted_bill_and_posts_balanced_ap_bank_journal(): void
    {
        [$company, $supplier, $bank, $bill, $creator, $approver, $ap, $bankLedger] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))->post(route('finance.payments.store'), [
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'payment_number' => 'PAY-001',
            'payment_date' => '2026-11-28', 'description' => 'Settlement', 'amount' => 75,
            'allocations' => [['bill_id' => $bill->id, 'amount' => 75]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.payments.post', $payment->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_payments', ['id' => $payment->id, 'status' => 'posted']);
        $this->assertDatabaseHas('bank_transactions', ['bank_account_id' => $bank->id, 'journal_entry_id' => $payment->journal_entry_id, 'direction' => 'out', 'amount' => 75]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ap->id, 'debit' => 75, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 75]);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('finance.payments.index'))->assertOk()->assertSee('Supplier payments');
    }

    public function test_payment_cannot_overpay_bill_and_creator_cannot_post_own_payment(): void
    {
        [$company, $supplier, $bank, $bill, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))->post(route('finance.payments.store'), [
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'payment_number' => 'PAY-002',
            'payment_date' => '2026-11-28', 'description' => 'Overpayment', 'amount' => 110,
            'allocations' => [['bill_id' => $bill->id, 'amount' => 110]],
        ])->assertSessionHasErrors('allocations');
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))->post(route('finance.payments.store'), [
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'payment_number' => 'PAY-003',
            'payment_date' => '2026-11-28', 'description' => 'Self-post guard', 'amount' => 50,
            'allocations' => [['bill_id' => $bill->id, 'amount' => 50]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('payment_number', 'PAY-003')->firstOrFail();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.payments.post', $payment->id))->assertForbidden();
        $this->assertDatabaseHas('supplier_payments', ['id' => $payment->id, 'status' => 'draft']);
    }

    public function test_cancelled_draft_releases_its_supplier_bill_allocation(): void
    {
        [$company, $supplier, $bank, $bill, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.payments.store'), [
                'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id,
                'payment_number' => 'PAY-CANCEL-1', 'payment_date' => '2026-11-28',
                'description' => 'Cancelled draft', 'amount' => 60,
                'allocations' => [['bill_id' => $bill->id, 'amount' => 60]],
            ])->assertRedirect()->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('payment_number', 'PAY-CANCEL-1')->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.payments.cancel', $payment->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_payments', ['id' => $payment->id, 'status' => 'cancelled']);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.payments.store'), [
                'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id,
                'payment_number' => 'PAY-CANCEL-2', 'payment_date' => '2026-11-28',
                'description' => 'Replacement draft', 'amount' => 100,
                'allocations' => [['bill_id' => $bill->id, 'amount' => 100]],
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Payables Co', 'is_active' => true]);
        $creator = $this->owner($company, 'payment-creator'); $approver = $this->owner($company, 'payment-approver');
        $ap = Account::create(['company_id' => $company->id, 'account_code' => '2000', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $expense = Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Operating expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash at bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'account_id' => $bankLedger->id, 'code' => 'BANK-1', 'name' => 'Main Bank', 'currency' => 'PGK', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-1', 'name' => 'Test Supplier', 'is_active' => true]);
        $bill = SupplierBill::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'expense_account_id' => $expense->id,
            'bill_number' => 'BILL-'.uniqid(), 'bill_date' => '2026-11-01', 'due_date' => '2026-11-30', 'amount' => 100,
            'currency' => 'PGK', 'description' => 'Test supplier bill', 'status' => 'posted', 'created_by' => $creator->id,
            'posted_by' => $approver->id, 'posted_at' => now()]);
        return [$company, $supplier, $bank, $bill, $creator, $approver, $ap, $bankLedger];
    }

    private function owner(Company $company, string $label): User
    {
        $user = User::create(['name' => $label, 'email' => $label.'-'.uniqid().'@example.test', 'password' => 'password']);
        $user->companies()->attach($company->id, ['role_code' => 'owner', 'is_active' => true]);
        return $user;
    }

    private function sessionData(Company $company, User $user): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
