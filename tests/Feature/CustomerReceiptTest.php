<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustomerReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_allocates_partial_invoice_payment_and_posts_unallocated_deposit(): void
    {
        [$company, $branch, $customer, $bank, $invoice, $creator, $approver, $ar, $bankLedger] = $this->fixture();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.receipts.store'), [
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'bank_account_id' => $bank->id,
                'receipt_number' => 'RCPT-001',
                'receipt_date' => '2026-11-28',
                'description' => 'Part payment',
                'amount' => 50,
                'allocations' => [['invoice_id' => $invoice->id, 'amount' => 40]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = CustomerReceipt::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.receipts.post', $receipt->id))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_receipts', ['id' => $receipt->id, 'status' => 'posted']);
        $this->assertDatabaseHas('bank_transactions', ['bank_account_id' => $bank->id, 'journal_entry_id' => $receipt->journal_entry_id, 'direction' => 'in', 'amount' => 50]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $bankLedger->id, 'debit' => 50, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ar->id, 'debit' => 0, 'credit' => 40]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => Account::where('company_id', $company->id)->where('account_code', '2500')->value('id'), 'debit' => 0, 'credit' => 10]);
    }

    public function test_receipt_cannot_overpay_invoice_after_prior_posted_receipts(): void
    {
        [$company, $branch, $customer, $bank, $invoice, $creator, $approver] = $this->fixture();
        $first = $this->createReceipt($company, $branch, $customer, $bank, $invoice, $creator, 'RCPT-002', 80, 80);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.receipts.post', $first->id))->assertRedirect()->assertSessionHasNoErrors();

        $second = $this->createReceipt($company, $branch, $customer, $bank, $invoice, $creator, 'RCPT-003', 30, 30);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.receipts.post', $second->id))->assertSessionHasErrors('allocations');

        $this->assertDatabaseHas('customer_receipts', ['id' => $second->id, 'status' => 'draft']);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Receivables Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 'receipt-creator');
        $approver = $this->owner($company, 'receipt-approver');
        $ar = Account::create(['company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash at bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $revenue = Account::create(['company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bankLedger->id, 'code' => 'BANK-1', 'name' => 'Main Bank', 'currency' => 'PGK', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-1', 'name' => 'Test Customer', 'is_active' => true]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'invoice_number' => 'INV-'.uniqid(),
            'invoice_date' => '2026-11-01', 'due_date' => '2026-11-30', 'amount' => 100,
            'currency' => 'PGK', 'description' => 'Test invoice', 'status' => 'posted',
            'created_by' => $creator->id, 'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        return [$company, $branch, $customer, $bank, $invoice, $creator, $approver, $ar, $bankLedger];
    }

    private function createReceipt(Company $company, Branch $branch, Customer $customer, BankAccount $bank, CustomerInvoice $invoice, User $creator, string $number, float $amount, float $allocated): CustomerReceipt
    {
        $receipt = CustomerReceipt::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'bank_account_id' => $bank->id, 'receipt_number' => $number, 'receipt_date' => '2026-11-28',
            'description' => 'Test payment', 'amount' => $amount, 'currency' => 'PGK',
            'status' => 'draft', 'created_by' => $creator->id,
        ]);
        DB::table('customer_receipt_allocations')->insert([
            'customer_receipt_id' => $receipt->id, 'customer_invoice_id' => $invoice->id,
            'amount' => $allocated, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $receipt;
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
