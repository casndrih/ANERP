<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\PayrollLedgerPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PayrollLedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_payroll_posts_balanced_journals_per_branch(): void
    {
        [$company, $user, $branchA, $branchB] = $this->fixture();
        $run = PayrollRun::create([
            'company_id' => $company->id, 'run_number' => 'PAY-LEDGER-001',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-14',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'fortnightly',
            'status' => 'approved',
        ]);
        $employeeA = $this->employee($company, $branchA, 'A');
        $employeeB = $this->employee($company, $branchB, 'B');
        $this->line($run, $employeeA, 1000, 100, 60, 84, 840);
        $this->line($run, $employeeB, 2000, 200, 120, 168, 1680);

        app(PayrollLedgerPoster::class)->post($run, $user->id);

        $this->assertDatabaseCount('payroll_run_journals', 2);
        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branchA->id,
            'source' => 'payroll_run', 'status' => 'posted',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branchB->id,
            'source' => 'payroll_run', 'status' => 'posted',
        ]);
        $this->assertSame(0.0, (float) \DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.source', 'payroll_run')
            ->selectRaw('ROUND(SUM(debit) - SUM(credit), 2) as difference')
            ->value('difference'));
    }

    public function test_payroll_posting_rejects_unapproved_run_and_duplicate_posting(): void
    {
        [$company, $user] = $this->fixture();
        $run = PayrollRun::create([
            'company_id' => $company->id, 'run_number' => 'PAY-LEDGER-002',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-14',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'fortnightly',
            'status' => 'draft',
        ]);

        try {
            app(PayrollLedgerPoster::class)->post($run, $user->id);
            self::fail('Draft payroll must not be posted.');
        } catch (ValidationException) {
            self::assertDatabaseCount('payroll_run_journals', 0);
        }

        $run->update(['status' => 'approved']);
        $employee = $this->employee($company, null, 'C');
        $this->line($run, $employee, 1000, 100, 60, 84, 840);
        app(PayrollLedgerPoster::class)->post($run, $user->id);

        try {
            app(PayrollLedgerPoster::class)->post($run, $user->id);
            self::fail('Payroll must not be posted twice.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payroll_run_journals', 1);
        }
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Payroll Ledger Co', 'is_active' => true]);
        $user = User::create(['name' => 'Payroll Approver', 'email' => uniqid().'@example.test', 'password' => 'password']);
        $branchA = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'code' => 'HGN', 'name' => 'Hagen', 'is_active' => true]);
        return [$company, $user, $branchA, $branchB];
    }

    private function employee(Company $company, ?Branch $branch, string $suffix): Employee
    {
        return Employee::create([
            'company_id' => $company->id, 'branch_id' => $branch?->id,
            'employee_number' => 'EMP-'.$suffix, 'first_name' => 'Test',
            'last_name' => $suffix, 'base_salary' => 1000,
            'pay_frequency' => 'fortnightly', 'tax_residency' => 'resident', 'status' => 'active',
        ]);
    }

    private function line(PayrollRun $run, Employee $employee, float $gross, float $tax, float $employeeSuper, float $employerSuper, float $net): void
    {
        PayrollLine::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'base_pay' => $gross, 'allowances' => 0, 'gross_pay' => $gross,
            'taxable_gross' => $gross, 'tax_deduction' => $tax,
            'employee_super' => $employeeSuper, 'employer_super' => $employerSuper,
            'net_pay' => $net,
        ]);
    }
}
