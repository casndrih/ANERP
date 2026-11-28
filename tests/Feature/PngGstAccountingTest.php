<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PngGstAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_company_posts_output_and_eligible_input_gst_to_control_accounts(): void
    {
        [$company, $branch, $creator, $approver, $supplier, $customer] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.tax-settings'), ['gst_registered' => 1, 'gst_registration_number' => 'GST-REG-001'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.bills.store'), [
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'bill_number' => 'BILL-GST-001',
                'bill_date' => '2026-11-10', 'due_date' => '2026-11-30', 'expense_account_id' => Account::where('company_id', $company->id)->where('account_code', '6400')->value('id'),
                'amount' => 100, 'description' => 'GST eligible purchase', 'gst_treatment' => 'standard',
                'supplier_gst_registered' => 1, 'tax_invoice_reference' => 'SUP-TAX-001',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $bill = SupplierBill::where('bill_number', 'BILL-GST-001')->firstOrFail();
        self::assertSame(110.0, (float) $bill->amount);
        self::assertSame(100.0, (float) $bill->net_amount);
        self::assertSame(10.0, (float) $bill->gst_amount);
        self::assertTrue((bool) $bill->input_tax_credit_eligible);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.bills.post', $bill->id))->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.invoices.store'), [
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'invoice_number' => 'INV-GST-001', 'invoice_date' => '2026-11-12', 'due_date' => '2026-11-30',
                'revenue_account_id' => Account::where('company_id', $company->id)->where('account_code', '4000')->value('id'),
                'amount' => 100, 'description' => 'GST taxable sale', 'gst_treatment' => 'standard',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = CustomerInvoice::where('invoice_number', 'INV-GST-001')->firstOrFail();
        self::assertSame(110.0, (float) $invoice->amount);
        self::assertSame(10.0, (float) $invoice->gst_amount);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.invoices.post', $invoice->id))->assertRedirect()->assertSessionHasNoErrors();

        $input = Account::where('company_id', $company->id)->where('account_code', '1300')->firstOrFail();
        $output = Account::where('company_id', $company->id)->where('account_code', '2100')->firstOrFail();
        $expense = Account::where('company_id', $company->id)->where('account_code', '6400')->firstOrFail();
        $ap = Account::where('company_id', $company->id)->where('account_code', '2000')->firstOrFail();
        $ar = Account::where('company_id', $company->id)->where('account_code', '1100')->firstOrFail();
        $revenue = Account::where('company_id', $company->id)->where('account_code', '4000')->firstOrFail();

        $this->assertDatabaseHas('journal_lines', ['account_id' => $expense->id, 'debit' => 100, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $input->id, 'debit' => 10, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ap->id, 'debit' => 0, 'credit' => 110]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ar->id, 'debit' => 110, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $output->id, 'debit' => 0, 'credit' => 10]);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('finance.gst-summary', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->assertSee('K 10.00')->assertSee('K 0.00');
    }

    public function test_supplier_gst_without_registration_or_tax_invoice_cannot_be_claimed(): void
    {
        [$company, $branch, $creator, $approver, $supplier] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.tax-settings'), ['gst_registered' => 1, 'gst_registration_number' => 'GST-REG-002'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.bills.store'), [
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'bill_number' => 'BILL-GST-NOREF',
                'bill_date' => '2026-11-10', 'expense_account_id' => Account::where('company_id', $company->id)->where('account_code', '6400')->value('id'),
                'amount' => 100, 'description' => 'No tax invoice evidence', 'gst_treatment' => 'standard',
                'supplier_gst_registered' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $bill = SupplierBill::where('bill_number', 'BILL-GST-NOREF')->firstOrFail();
        self::assertSame(110.0, (float) $bill->amount);
        self::assertFalse((bool) $bill->input_tax_credit_eligible);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'GST Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 'gst-creator'); $approver = $this->owner($company, 'gst-approver');
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-GST', 'name' => 'GST Supplier', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-GST', 'name' => 'GST Customer', 'is_active' => true]);
        return [$company, $branch, $creator, $approver, $supplier, $customer];
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
