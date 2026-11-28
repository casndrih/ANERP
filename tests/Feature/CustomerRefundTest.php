<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_of_unallocated_customer_deposit_posts_to_bank_and_customer_deposits(): void
    {
        [$company, $branch, $customer, $bank, $receipt, $creator, $approver, $depositAccount, $bankLedger] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.refunds.store'), [
                'branch_id' => $branch->id, 'receipt_id' => $receipt->id, 'bank_account_id' => $bank->id,
                'refund_number' => 'REF-001', 'refund_date' => '2026-11-28',
                'description' => 'Unused customer deposit', 'amount' => 40,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $refund = \App\Models\CustomerRefund::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.refunds.post', $refund->id))->assertRedirect()->assertSessionHasNoErrors();
        $refund->refresh();
        $this->assertDatabaseHas('customer_refunds', ['id' => $refund->id, 'status' => 'posted']);
        $this->assertDatabaseHas('bank_transactions', ['bank_account_id' => $bank->id, 'journal_entry_id' => $refund->journal_entry_id, 'direction' => 'out', 'amount' => '40.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $depositAccount->id, 'debit' => 40, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $bankLedger->id, 'debit' => 0, 'credit' => 40]);
    }

    public function test_refund_cannot_exceed_unallocated_deposit_and_draft_can_be_cancelled(): void
    {
        [$company, $branch, $customer, $bank, $receipt, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.refunds.store'), [
                'branch_id' => $branch->id, 'receipt_id' => $receipt->id, 'bank_account_id' => $bank->id,
                'refund_number' => 'REF-OVER', 'refund_date' => '2026-11-28',
                'description' => 'Over refund', 'amount' => 101,
            ])->assertSessionHasErrors('amount');
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.refunds.store'), [
                'branch_id' => $branch->id, 'receipt_id' => $receipt->id, 'bank_account_id' => $bank->id,
                'refund_number' => 'REF-CANCEL', 'refund_date' => '2026-11-28',
                'description' => 'Cancelled refund', 'amount' => 40,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $refund = \App\Models\CustomerRefund::where('refund_number', 'REF-CANCEL')->firstOrFail();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.refunds.store'), [
                'branch_id' => $branch->id, 'receipt_id' => $receipt->id, 'bank_account_id' => $bank->id,
                'refund_number' => 'REF-RESERVED', 'refund_date' => '2026-11-28',
                'description' => 'Over reserved balance', 'amount' => 61,
            ])->assertSessionHasErrors('amount');
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.refunds.cancel', $refund->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_refunds', ['id' => $refund->id, 'status' => 'cancelled']);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.refunds.store'), [
                'branch_id' => $branch->id, 'receipt_id' => $receipt->id, 'bank_account_id' => $bank->id,
                'refund_number' => 'REF-REPLACEMENT', 'refund_date' => '2026-11-28',
                'description' => 'Replacement refund', 'amount' => 100,
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Refund Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 'refund-creator'); $approver = $this->owner($company, 'refund-approver');
        $depositAccount = Account::create(['company_id' => $company->id, 'account_code' => '2500', 'name' => 'Customer deposits', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash at bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bankLedger->id, 'code' => 'BANK-1', 'name' => 'Main Bank', 'currency' => 'PGK', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-1', 'name' => 'Test Customer', 'is_active' => true]);
        $receipt = CustomerReceipt::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'bank_account_id' => $bank->id, 'receipt_number' => 'RCPT-'.uniqid(), 'receipt_date' => '2026-11-20',
            'description' => 'Deposit receipt', 'amount' => 100, 'currency' => 'PGK',
            'status' => 'posted', 'created_by' => $creator->id, 'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        return [$company, $branch, $customer, $bank, $receipt, $creator, $approver, $depositAccount, $bankLedger];
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
