<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PosSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_cash_sale_posts_receipt_and_closes_with_reconciled_cash(): void
    {
        [$company, $branch, $warehouse, $bank, $customer, $cashier, $manager, $invoice] = $this->fixture();

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.open'), [
                'warehouse_id' => $warehouse->id, 'cash_bank_account_id' => $bank->id,
                'session_number' => 'TILL-001', 'opening_float' => 50,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $session = DB::table('pos_sessions')->where('session_number', 'TILL-001')->first();

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.sale', $session->id), [
                'invoice_id' => $invoice->id, 'transaction_number' => 'POS-001',
                'cash_tendered' => 120, 'reference' => 'CASH-001',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = DB::table('pos_transactions')->where('transaction_number', 'POS-001')->first();
        self::assertSame(110.0, (float) $transaction->amount);
        self::assertSame(120.0, (float) $transaction->cash_tendered);
        self::assertSame(10.0, (float) $transaction->change_amount);
        $receipt = DB::table('customer_receipts')->where('id', $transaction->customer_receipt_id)->first();
        self::assertSame('posted', $receipt->status);
        self::assertNotNull($receipt->journal_entry_id);
        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $bank->id, 'journal_entry_id' => $receipt->journal_entry_id,
            'direction' => 'in', 'amount' => '110.00',
        ]);
        self::assertSame(160.0, (float) DB::table('pos_sessions')->where('id', $session->id)->value('expected_cash'));

        $this->actingAs($manager)->withSession($this->sessionData($company, $manager))
            ->post(route('sales.pos.close', $session->id), ['counted_cash' => 160, 'notes' => 'Till balanced'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $closed = DB::table('pos_sessions')->where('id', $session->id)->first();
        self::assertSame('closed', $closed->status);
        self::assertSame(0.0, (float) $closed->cash_variance);
    }

    public function test_pos_rejects_short_cash_and_prevents_duplicate_open_sessions(): void
    {
        [$company, $branch, $warehouse, $bank, $customer, $cashier, $manager, $invoice] = $this->fixture();
        $payload = ['warehouse_id' => $warehouse->id, 'cash_bank_account_id' => $bank->id, 'session_number' => 'TILL-002', 'opening_float' => 20];
        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.open'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $session = DB::table('pos_sessions')->where('session_number', 'TILL-002')->first();

        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.open'), array_merge($payload, ['session_number' => 'TILL-003']))->assertSessionHasErrors('session_number');
        $this->actingAs($cashier)->withSession($this->sessionData($company, $cashier))
            ->post(route('sales.pos.sale', $session->id), [
                'invoice_id' => $invoice->id, 'transaction_number' => 'POS-SHORT',
                'cash_tendered' => 50,
            ])->assertSessionHasErrors('cash_tendered');
        $this->assertDatabaseMissing('pos_transactions', ['transaction_number' => 'POS-SHORT']);
        $this->assertDatabaseHas('pos_sessions', ['id' => $session->id, 'status' => 'open']);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'POS Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'POS-WH', 'name' => 'POS Warehouse', 'is_active' => true]);
        $bankLedger = Account::create(['company_id' => $company->id, 'account_code' => '1000', 'name' => 'Cash drawer', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $ar = Account::create(['company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $revenue = Account::create(['company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $gst = Account::create(['company_id' => $company->id, 'account_code' => '2100', 'name' => 'GST output', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $bank = BankAccount::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bankLedger->id, 'code' => 'CASH-01', 'name' => 'Main Till', 'currency' => 'PGK', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'POS-CUST', 'name' => 'POS Customer', 'is_active' => true]);
        $cashier = $this->owner($company, 'pos-cashier');
        $manager = $this->owner($company, 'pos-manager');
        $journal = JournalEntry::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'journal_number' => 'INV-POS-001',
            'journal_date' => '2026-11-28', 'source' => 'customer_invoice', 'status' => 'posted',
            'description' => 'POS test invoice', 'created_by' => $cashier->id, 'posted_by' => $manager->id, 'posted_at' => now(),
        ]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'journal_entry_id' => $journal->id,
            'invoice_number' => 'INV-POS-001', 'invoice_date' => '2026-11-28',
            'amount' => 110, 'net_amount' => 100, 'gst_treatment' => 'standard',
            'gst_rate' => 10, 'gst_amount' => 10, 'currency' => 'PGK',
            'description' => 'POS test invoice', 'status' => 'posted',
            'created_by' => $cashier->id, 'posted_by' => $manager->id, 'posted_at' => now(),
        ]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $ar->id, 'description' => 'AR', 'debit' => 110, 'credit' => 0]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $revenue->id, 'description' => 'Revenue', 'debit' => 0, 'credit' => 100]);
        JournalLine::create(['journal_entry_id' => $journal->id, 'account_id' => $gst->id, 'description' => 'GST', 'debit' => 0, 'credit' => 10]);
        return [$company, $branch, $warehouse, $bank, $customer, $cashier, $manager, $invoice];
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
