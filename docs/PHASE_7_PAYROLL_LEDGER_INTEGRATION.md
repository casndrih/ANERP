# Phase 7 — Payroll-to-general-ledger integration

## Included
- Approval of a draft payroll run creates posted double-entry accrual journals automatically.
- Journals are split by employee branch, including a separate company-wide / unassigned branch group where applicable.
- Debits: gross wages expense (6000) and employer superannuation expense (6100).
- Credits: PAYE payable (2200), employee super payable (2300), employer super payable (2400), and net wages payable (2500).
- Each branch journal is linked to the payroll run in `payroll_run_journals`.
- Posting is transactional and idempotency-protected; an approved run cannot be posted twice.
- Payroll approval remains the maker-checker authorization. Generated journals are system-posted with the approving user recorded as poster.

## Accounting treatment and boundaries
- This is an accrual, not a payment: net pay is credited to wages payable. It does not reduce bank balances or imply employees have been paid.
- PAYE and both superannuation liabilities remain payable until payment workflows and statutory remittance allocation are implemented.
- Account codes are seeded per company only when absent. Existing codes with incompatible account type / normal balance block posting for accountant review.
- The journals inherit each employee's branch; historical employee branch assignments are not guessed.
- Inventory valuation and inventory-to-ledger journals remain a separate next integration because receipt/issue/transfer valuation and inter-branch clearing policy must be defined before automatic posting.

## Acceptance checklist
- [ ] Approve a payroll run with employees in multiple branches and verify one balanced journal per branch.
- [ ] Confirm PAYE, employee super, employer super and net wages payable credit balances.
- [ ] Confirm a second posting attempt is rejected.
- [ ] Reconcile the payroll journal totals against the approved payroll register.
- [ ] Accountant approves the chart mapping and opening liability treatment before production use.
