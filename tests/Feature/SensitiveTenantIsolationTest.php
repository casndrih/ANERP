<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SensitiveTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_workspace_only_lists_the_active_company_journals(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('finance_manager');

        $this->journal($companyA, $user, 'A-FINANCE-ONLY');
        $this->journal($companyB, $user, 'B-FINANCE-SECRET');

        $response = $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->get(route('finance.index'));

        $response->assertOk()->assertViewHas('journals', function ($journals) {
            return $journals->contains('journal_number', 'A-FINANCE-ONLY')
                && !$journals->contains('journal_number', 'B-FINANCE-SECRET');
        });
    }

    public function test_payroll_workspace_only_lists_the_active_company_runs(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('payroll_manager');

        PayrollRun::create([
            'company_id' => $companyA->id, 'run_number' => 'A-PAYROLL-ONLY',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'monthly',
        ]);
        PayrollRun::create([
            'company_id' => $companyB->id, 'run_number' => 'B-PAYROLL-SECRET',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'monthly',
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->get(route('payroll.index'));

        $response->assertOk()->assertViewHas('runs', function ($runs) {
            return $runs->contains('run_number', 'A-PAYROLL-ONLY')
                && !$runs->contains('run_number', 'B-PAYROLL-SECRET');
        });
    }

    public function test_procurement_workspace_only_lists_the_active_company_orders(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('procurement_manager');

        $supplierA = Supplier::create(['company_id' => $companyA->id, 'code' => 'SUP-A', 'name' => 'Supplier A']);
        $warehouseA = Warehouse::create(['company_id' => $companyA->id, 'code' => 'WH-A', 'name' => 'Warehouse A']);
        $supplierB = Supplier::create(['company_id' => $companyB->id, 'code' => 'SUP-B', 'name' => 'Supplier B']);
        $warehouseB = Warehouse::create(['company_id' => $companyB->id, 'code' => 'WH-B', 'name' => 'Warehouse B']);

        PurchaseOrder::create([
            'company_id' => $companyA->id, 'supplier_id' => $supplierA->id,
            'warehouse_id' => $warehouseA->id, 'order_number' => 'A-PO-ONLY',
            'order_date' => '2026-11-20', 'status' => 'draft', 'currency' => 'PGK',
            'subtotal' => 10, 'tax_total' => 0, 'total' => 10, 'created_by' => $user->id,
        ]);
        PurchaseOrder::create([
            'company_id' => $companyB->id, 'supplier_id' => $supplierB->id,
            'warehouse_id' => $warehouseB->id, 'order_number' => 'B-PO-SECRET',
            'order_date' => '2026-11-20', 'status' => 'draft', 'currency' => 'PGK',
            'subtotal' => 20, 'tax_total' => 0, 'total' => 20, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->get(route('procurement.orders.index'));

        $response->assertOk()->assertViewHas('orders', function ($orders) {
            return $orders->contains('order_number', 'A-PO-ONLY')
                && !$orders->contains('order_number', 'B-PO-SECRET');
        });
    }

    public function test_finance_report_export_excludes_another_company_ledger_rows(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('finance_manager');

        $accountA = Account::create([
            'company_id' => $companyA->id, 'account_code' => 'A-4000',
            'name' => 'Tenant A permitted ledger', 'account_type' => 'income',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);
        $accountB = Account::create([
            'company_id' => $companyB->id, 'account_code' => 'B-4000',
            'name' => 'Tenant B confidential ledger', 'account_type' => 'income',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);

        foreach ([[$companyA, $accountA, 'A-LEDGER'], [$companyB, $accountB, 'B-LEDGER']] as [$company, $account, $number]) {
            $entry = JournalEntry::create([
                'company_id' => $company->id, 'journal_number' => $number,
                'journal_date' => '2026-11-20', 'source' => 'manual',
                'status' => 'posted', 'description' => $number,
                'created_by' => $user->id, 'posted_by' => $user->id, 'posted_at' => now(),
            ]);
            JournalLine::create([
                'journal_entry_id' => $entry->id, 'account_id' => $account->id,
                'description' => $number, 'debit' => 0, 'credit' => 125,
            ]);
        }

        $response = $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->get(route('reports.export', [
                'report' => 'trial_balance', 'from' => '2026-11-01', 'to' => '2026-11-30',
            ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Tenant A permitted ledger', $csv);
        $this->assertStringNotContainsString('Tenant B confidential ledger', $csv);
    }

    public function test_finance_user_cannot_post_another_companys_journal(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('finance_manager');
        $foreignJournal = $this->journal($companyB, $user, 'B-FOREIGN-JOURNAL');

        $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->post(route('finance.journals.post', $foreignJournal->id))
            ->assertNotFound();

        $this->assertDatabaseHas('journal_entries', [
            'id' => $foreignJournal->id, 'company_id' => $companyB->id, 'status' => 'draft',
        ]);
    }

    public function test_payroll_user_cannot_approve_another_companys_run(): void
    {
        [$user, $companyA, $companyB] = $this->userWithCompanies('payroll_manager');
        $foreignRun = PayrollRun::create([
            'company_id' => $companyB->id, 'run_number' => 'B-FOREIGN-PAYROLL',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-14',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'fortnightly',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($user, $companyA))
            ->post(route('payroll.approve', $foreignRun->id))
            ->assertNotFound();

        $this->assertDatabaseHas('payroll_runs', [
            'id' => $foreignRun->id, 'company_id' => $companyB->id, 'status' => 'draft',
        ]);
    }

    private function journal(Company $company, User $user, string $number): JournalEntry
    {
        return JournalEntry::create([
            'company_id' => $company->id, 'journal_number' => $number,
            'journal_date' => '2026-11-20', 'source' => 'manual',
            'status' => 'draft', 'description' => $number,
            'created_by' => $user->id,
        ]);
    }

    private function userWithCompanies(string $role): array
    {
        $user = User::create([
            'name' => 'Tenant Security Test',
            'email' => 'security-' . uniqid() . '@example.test',
            'password' => 'test-password',
        ]);
        $user->forceFill([
            'mfa_enabled_at' => now(),
            'mfa_secret' => 'test-encrypted-secret',
        ])->save();

        $companyA = Company::create(['name' => 'Tenant A', 'is_active' => true]);
        $companyB = Company::create(['name' => 'Tenant B', 'is_active' => true]);
        $user->companies()->attach([
            $companyA->id => ['role_code' => $role, 'is_active' => true],
            $companyB->id => ['role_code' => $role, 'is_active' => true],
        ]);

        return [$user, $companyA, $companyB];
    }

    private function companySession(User $user, Company $company): array
    {
        return [
            'active_company_id' => $company->id,
            'mfa_verified_user_id' => $user->id,
        ];
    }
}
