<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PosRefundVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_cash_refund_is_maker_checker_limited_to_credit_deposit_and_reconciles_till(): void
    {
        [$company, $branch, $warehouse, $bank, $cashier, $manager, $invoice, $accounts] = $this->fixture();
        $session = $this->openTill($company, $warehouse, $bank, $cashier, 'TILL-REFUND');
        $transaction = $this->makeSale($company, $cashier, $session, $invoice, 'POS-REFUND-001');

        $note = $this->returnCreditNote($company, $branch, $warehouse, $invoice, $cashier, 'SR-POS-REFUND', 110);
        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.refunds.store', $session), [
                'original_pos_transaction_id' => $transaction->id,
                'customer_credit_note_id' => $note->id,
                'amount' => 60, 'reason' => 'Approved partial cash refund',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $refund = DB::table('pos_refunds')->where('customer_credit_note_id', $note->id)->first();

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.refunds.post', $refund->id))->assertForbidden();
        $this->actingAs($manager)->withSession($this->sessionData($company, $manager))
            ->post(route('sales.pos.refunds.post', $refund->id))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pos_refunds', ['id' => $refund->id, 'status' => 'posted', 'amount' => '60.00']);
        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '60.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['deposits']->id, 'debit' => '60.00', 'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['cash']->id, 'debit' => '0.00', 'credit' => '60.00',
        ]);
        self::assertSame(100.0, (float) DB::table('pos_sessions')->where('id', $session)->value('expected_cash'));

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.refunds.store', $session), [
                'original_pos_transaction_id' => $transaction->id,
                'customer_credit_note_id' => $note->id,
                'amount' => 60, 'reason' => 'Attempt to exceed remaining deposit',
            ])->assertSessionHasErrors('amount');
    }

    public function test_pos_void_reverses_receipt_reopens_invoice_and_allows_a_new_cash_sale(): void
    {
        [$company, $branch, $warehouse, $bank, $cashier, $manager, $invoice, $accounts] = $this->fixture();
        $session = $this->openTill($company, $warehouse, $bank, $cashier, 'TILL-VOID');
        $transaction = $this->makeSale($company, $cashier, $session, $invoice, 'POS-VOID-001');

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.voids.store', $session), [
                'pos_transaction_id' => $transaction->id, 'reason' => 'Duplicate till entry',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $void = DB::table('pos_voids')->where('pos_transaction_id', $transaction->id)->first();

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.voids.post', $void->id))->assertForbidden();
        $this->actingAs($manager)->withSession($this->sessionData($company, $manager))
            ->post(route('sales.pos.voids.post', $void->id))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pos_voids', ['id' => $void->id, 'status' => 'posted']);
        $this->assertDatabaseHas('pos_transactions', ['id' => $transaction->id, 'status' => 'voided']);
        $this->assertDatabaseHas('customer_receipts', ['id' => $transaction->customer_receipt_id, 'status' => 'reversed']);
        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '110.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['ar']->id, 'debit' => '110.00', 'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['cash']->id, 'debit' => '0.00', 'credit' => '110.00',
        ]);
        self::assertSame(50.0, (float) DB::table('pos_sessions')->where('id', $session)->value('expected_cash'));

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.sale', $session), [
                'invoice_id' => $invoice->id, 'transaction_number' => 'POS-VOID-RETRY',
                'cash_tendered' => 110,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pos_transactions', [
            'customer_invoice_id' => $invoice->id, 'transaction_number' => 'POS-VOID-RETRY', 'status' => 'posted',
        ]);
    }

    public function test_pos_void_is_blocked_after_another_customer_receipt_is_allocated(): void
    {
        [$company, $branch, $warehouse, $bank, $cashier, $manager, $invoice] = $this->fixture();
        $session = $this->openTill($company, $warehouse, $bank, $cashier, 'TILL-VOID-BLOCK');
        $transaction = $this->makeSale($company, $cashier, $session, $invoice, 'POS-VOID-BLOCK');

        $extraReceipt = DB::table('customer_receipts')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $invoice->customer_id,
            'bank_account_id' => $bank->id, 'receipt_number' => 'EXTRA-RECEIPT',
            'receipt_date' => '2026-11-28', 'description' => 'Subsequent receipt',
            'amount' => 1, 'currency' => 'PGK', 'status' => 'posted', 'created_by' => $cashier->id,
            'posted_by' => $manager->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_receipt_allocations')->insert([
            'customer_receipt_id' => $extraReceipt, 'customer_invoice_id' => $invoice->id,
            'amount' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.voids.store', $session), [
                'pos_transaction_id' => $transaction->id, 'reason' => 'Should be blocked',
            ])->assertSessionHasErrors('pos_transaction_id');
        $this->assertDatabaseMissing('pos_voids', ['pos_transaction_id' => $transaction->id]);
    }

    private function openTill(Company $company, Warehouse $warehouse, BankAccount $bank, User $cashier, string $number): int
    {
        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.open'), [
                'warehouse_id' => $warehouse->id, 'cash_bank_account_id' => $bank->id,
                'session_number' => $number, 'opening_float' => 50,
            ])->assertRedirect()->assertSessionHasNoErrors();
        return (int) DB::table('pos_sessions')->where('session_number', $number)->value('id');
    }

    private function makeSale(Company $company, User $cashier, int $session, CustomerInvoice $invoice, string $number): object
    {
        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.sale', $session), [
                'invoice_id' => $invoice->id, 'transaction_number' => $number, 'cash_tendered' => 110,
            ])->assertRedirect()->assertSessionHasNoErrors();
        return DB::table('pos_transactions')->where('transaction_number', $number)->first();
    }

    private function returnCreditNote(Company $company, Branch $branch, Warehouse $warehouse, CustomerInvoice $invoice, User $creator, string $number, float $deposit): CustomerCreditNote
    {
        $returnId = DB::table('customer_sales_returns')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $invoice->customer_id,
            'customer_invoice_id' => $invoice->id, 'warehouse_id' => $warehouse->id,
            'return_number' => $number, 'return_date' => '2026-11-28', 'reason' => 'POS refund source',
            'gst_treatment' => 'standard', 'gst_rate' => 10, 'net_amount' => 100, 'gst_amount' => 10,
            'total_amount' => 110, 'currency' => 'PGK', 'status' => 'posted', 'created_by' => $creator->id,
            'posted_by' => $creator->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $note = CustomerCreditNote::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $invoice->customer_id,
            'customer_invoice_id' => $invoice->id, 'source_sales_return_id' => $returnId,
            'credit_note_number' => 'CN-'.$number, 'credit_note_date' => '2026-11-28',
            'description' => 'POS refund test credit', 'amount' => 110, 'deposit_amount' => $deposit,
            'net_amount' => 100, 'gst_amount' => 10, 'gst_rate' => 10, 'gst_treatment' => 'standard',
            'currency' => 'PGK', 'status' => 'posted', 'created_by' => $creator->id,
            'posted_by' => $creator->id, 'posted_at' => now(),
        ]);
        DB::table('customer_sales_returns')->where('id', $returnId)->update(['customer_credit_note_id' => $note->id]);
        return $note;
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'POS Refund Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'POS-WH', 'name' => 'POS Warehouse', 'is_active' => true]);
        $cash = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Till cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $ar = Account::create(['company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $revenue = Account::create(['company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $gst = Account::create(['company_id' => $company->id, 'account_code' => '2100', 'name' => 'GST output', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $deposits = Account::create(['company_id' => $company->id, 'account_code' => '2500', 'name' => 'Customer deposits', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $cash->id, 'code' => 'POS-CASH', 'name' => 'POS Cash Drawer', 'currency' => 'PGK', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'POS-CUST', 'name' => 'POS Customer', 'is_active' => true]);
        $cashier = $this->owner($company, 'pos-refund-cashier');
        $manager = $this->owner($company, 'pos-refund-manager');
        $journal = JournalEntry::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'journal_number' => 'POS-INV-JV',
            'journal_date' => '2026-11-28', 'source' => 'customer_invoice', 'status' => 'posted',
            'description' => 'POS test invoice', 'created_by' => $cashier->id, 'posted_by' => $manager->id, 'posted_at' => now(),
        ]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'journal_entry_id' => $journal->id,
            'invoice_number' => 'INV-POS-REFUND', 'invoice_date' => '2026-11-28',
            'amount' => 110, 'net_amount' => 100, 'gst_treatment' => 'standard',
            'gst_rate' => 10, 'gst_amount' => 10, 'currency' => 'PGK',
            'description' => 'POS test invoice', 'status' => 'posted',
            'created_by' => $cashier->id, 'posted_by' => $manager->id, 'posted_at' => now(),
        ]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ar->id, 'description' => 'AR', 'debit' => 110, 'credit' => 0]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $revenue->id, 'description' => 'Revenue', 'debit' => 0, 'credit' => 100]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $gst->id, 'description' => 'GST', 'debit' => 0, 'credit' => 10]);
        return [$company, $branch, $warehouse, $bank, $cashier, $manager, $invoice, [
            'cash' => $cash, 'ar' => $ar, 'revenue' => $revenue, 'gst' => $gst, 'deposits' => $deposits,
        ]];
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
