<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollLedgerPoster
{
    /**
     * Post an approved payroll run to the GL, one journal per employee branch.
     * The payroll approval is the authorization event; generated journals are
     * system-posted and cannot be independently re-posted.
     */
    public function post(PayrollRun $run, int $approvedBy): void
    {
        DB::transaction(function () use ($run, $approvedBy) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== 'approved') {
                throw ValidationException::withMessages([
                    'payroll' => 'Only an approved payroll run can be posted to the general ledger.',
                ]);
            }

            $lines = $run->lines()->with('employee')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['payroll' => 'Payroll run has no employee lines.']);
            }

            $alreadyPosted = DB::table('payroll_run_journals')
                ->where('payroll_run_id', $run->id)->exists();
            if ($alreadyPosted) {
                throw ValidationException::withMessages(['payroll' => 'This payroll run already has general-ledger journals.']);
            }

            $companyId = (int) $run->company_id;
            $accounts = $this->accounts($companyId);
            $groups = $lines->groupBy(fn ($line) => $line->employee?->branch_id);

            foreach ($groups as $branchKey => $branchLines) {
                $branchId = $branchKey === '' || $branchKey === null ? null : (int) $branchKey;
                $gross = round((float) $branchLines->sum('gross_pay'), 2);
                $paye = round((float) $branchLines->sum('tax_deduction'), 2);
                $employeeSuper = round((float) $branchLines->sum('employee_super'), 2);
                $employerSuper = round((float) $branchLines->sum('employer_super'), 2);
                $net = round((float) $branchLines->sum('net_pay'), 2);

                if ($gross < 0 || $paye < 0 || $employeeSuper < 0 || $employerSuper < 0 || $net < 0) {
                    throw ValidationException::withMessages(['payroll' => 'Payroll contains a negative amount and cannot be posted.']);
                }

                $debit = round($gross + $employerSuper, 2);
                $credit = round($paye + $employeeSuper + $employerSuper + $net, 2);
                if (abs($debit - $credit) > 0.009) {
                    throw ValidationException::withMessages([
                        'payroll' => 'Payroll totals do not balance. Review net pay, PAYE and superannuation before approval.',
                    ]);
                }

                $entry = JournalEntry::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'journal_number' => 'PAY-GL-' . $run->id . '-' . ($branchId ?? 'HQ'),
                    'journal_date' => $run->period_end,
                    'source' => 'payroll_run',
                    'status' => 'posted',
                    'description' => 'Payroll accrual ' . $run->run_number,
                    'created_by' => $run->created_by,
                    'posted_by' => $approvedBy,
                    'posted_at' => now(),
                ]);

                $journalLines = [
                    [$accounts['wages_expense']->id, $gross, 0, 'Gross wages expense'],
                    [$accounts['employer_super_expense']->id, $employerSuper, 0, 'Employer superannuation expense'],
                    [$accounts['paye_payable']->id, 0, $paye, 'PAYE withheld payable to IRC'],
                    [$accounts['employee_super_payable']->id, 0, $employeeSuper, 'Employee superannuation payable'],
                    [$accounts['employer_super_payable']->id, 0, $employerSuper, 'Employer superannuation payable'],
                    [$accounts['wages_payable']->id, 0, $net, 'Net wages payable to employees'],
                ];

                foreach ($journalLines as [$accountId, $dr, $cr, $description]) {
                    if ($dr == 0.0 && $cr == 0.0) {
                        continue;
                    }
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accountId,
                        'description' => $description,
                        'debit' => $dr,
                        'credit' => $cr,
                    ]);
                }

                DB::table('payroll_run_journals')->insert([
                    'payroll_run_id' => $run->id,
                    'journal_entry_id' => $entry->id,
                    'branch_id' => $branchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function accounts(int $companyId): array
    {
        $definitions = [
            'wages_expense' => ['6000', 'Salaries and wages', 'expense', 'debit'],
            'employer_super_expense' => ['6100', 'Employer superannuation expense', 'expense', 'debit'],
            'paye_payable' => ['2200', 'PAYE payable', 'liability', 'credit'],
            'employee_super_payable' => ['2300', 'Employee super payable', 'liability', 'credit'],
            'employer_super_payable' => ['2400', 'Employer super payable', 'liability', 'credit'],
            'wages_payable' => ['2500', 'Net wages payable', 'liability', 'credit'],
        ];

        $accounts = [];
        foreach ($definitions as $key => [$code, $name, $type, $normal]) {
            $accounts[$key] = Account::firstOrCreate(
                ['company_id' => $companyId, 'account_code' => $code],
                ['name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_control_account' => true, 'is_active' => true]
            );
            if ($accounts[$key]->account_type !== $type || $accounts[$key]->normal_balance !== $normal) {
                throw ValidationException::withMessages([
                    'payroll' => "Chart account {$code} has an incompatible account type or normal balance. Correct the chart before posting payroll.",
                ]);
            }
        }

        return $accounts;
    }
}
