<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CustomerCreditPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_sales_orders_cannot_exceed_customer_credit_limit(): void
    {
        [$company, $branch, $warehouse, $customer, $user] = $this->fixture(500);

        $first = $this->order($company, $branch, $warehouse, $customer, $user, 'CR-001', 400);
        $first->update(['status' => 'approved']);

        $second = $this->order($company, $branch, $warehouse, $customer, $user, 'CR-002', 200);

        try {
            $second->update(['status' => 'approved']);
            self::fail('Expected the credit-limit policy to reject the second order.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertDatabaseHas('sales_orders', ['id' => $second->id, 'status' => 'draft']);
    }

    public function test_customer_credit_hold_blocks_sales_order_approval(): void
    {
        [$company, $branch, $warehouse, $customer, $user] = $this->fixture(null, true);
        $order = $this->order($company, $branch, $warehouse, $customer, $user, 'CR-HOLD-001', 25);

        try {
            $order->update(['status' => 'approved']);
            self::fail('Expected a credit hold to reject order approval.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('credit hold', strtolower($exception->errors()['customer_id'][0]));
        }

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'draft']);
    }

    public function test_invoice_posting_is_blocked_by_credit_hold_and_limit(): void
    {
        [$company, $branch, $warehouse, $customer, $user] = $this->fixture(100, true);
        $revenue = Account::create([
            'company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales',
            'account_type' => 'income', 'normal_balance' => 'credit',
            'is_control_account' => false, 'is_active' => true,
        ]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'invoice_number' => 'CR-HOLD-INV',
            'invoice_date' => '2026-11-28', 'amount' => 50, 'currency' => 'PGK',
            'description' => 'Credit hold test', 'status' => 'draft', 'created_by' => $user->id,
        ]);

        try {
            $invoice->update(['status' => 'posted']);
            self::fail('Expected a credit hold to reject invoice posting.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertDatabaseHas('customer_invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    public function test_posted_receipts_and_credit_notes_release_customer_credit(): void
    {
        [$company, $branch, $warehouse, $customer, $user] = $this->fixture(500);
        $ar = Account::create([
            'company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable',
            'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_control_account' => true, 'is_active' => true,
        ]);
        $revenue = Account::create([
            'company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales',
            'account_type' => 'income', 'normal_balance' => 'credit',
            'is_control_account' => false, 'is_active' => true,
        ]);
        $bank = BankAccount::create([
            'company_id' => $company->id, 'account_id' => $ar->id, 'code' => 'CR-BANK',
            'name' => 'Credit Test Bank', 'currency' => 'PGK', 'is_active' => true,
        ]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'invoice_number' => 'CR-OFFSET-INV',
            'invoice_date' => '2026-11-01', 'amount' => 450, 'currency' => 'PGK',
            'description' => 'Credit exposure test', 'status' => 'posted', 'created_by' => $user->id,
        ]);
        $receipt = CustomerReceipt::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'bank_account_id' => $bank->id, 'receipt_number' => 'CR-OFFSET-RCPT',
            'receipt_date' => '2026-11-20', 'description' => 'Credit offset receipt',
            'amount' => 100, 'currency' => 'PGK', 'status' => 'posted', 'created_by' => $user->id,
        ]);
        DB::table('customer_receipt_allocations')->insert([
            'customer_receipt_id' => $receipt->id, 'customer_invoice_id' => $invoice->id,
            'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        CustomerCreditNote::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'customer_invoice_id' => $invoice->id, 'credit_note_number' => 'CR-OFFSET-CN',
            'credit_note_date' => '2026-11-21', 'description' => 'Credit exposure offset',
            'amount' => 50, 'currency' => 'PGK', 'status' => 'posted', 'created_by' => $user->id,
        ]);

        $order = $this->order($company, $branch, $warehouse, $customer, $user, 'CR-OFFSET-SO', 200);
        $order->update(['status' => 'approved']);

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'approved']);
    }

    private function fixture(?float $limit, bool $hold = false): array
    {
        $company = Company::create(['name' => 'Credit Policy Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CREDIT-WH', 'name' => 'Credit Warehouse', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $company->id, 'code' => 'CREDIT-CUST', 'name' => 'Credit Customer',
            'is_active' => true, 'credit_limit' => $limit, 'credit_hold' => $hold,
            'credit_hold_reason' => $hold ? 'Overdue account review' : null,
        ]);
        $user = User::create(['name' => 'Credit User', 'email' => 'credit-'.uniqid().'@example.test', 'password' => 'password']);
        return [$company, $branch, $warehouse, $customer, $user];
    }

    private function order(Company $company, Branch $branch, Warehouse $warehouse, Customer $customer, User $user, string $number, float $amount): SalesOrder
    {
        return SalesOrder::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'order_number' => $number, 'order_date' => '2026-11-28',
            'status' => 'draft', 'gst_treatment' => 'out_of_scope',
            'net_amount' => $amount, 'gst_rate' => 0, 'gst_amount' => 0,
            'total_amount' => $amount, 'created_by' => $user->id,
        ]);
    }
}
