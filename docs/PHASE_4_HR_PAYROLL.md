# Phase 4 — HR and payroll

## Included
- Company-scoped employee directory with employee number, branch, department, job title, employment details, PGK base salary and pay frequency.
- Leave types and leave applications with HR approval and creator self-approval prevention.
- Daily attendance entries.
- Draft payroll runs, maker-checker approval and payslips.
- PNG salary and wages tax (resident/non-resident fortnightly bands), employment-declaration withholding and approved dependant credit handling.
- PNG superannuation contributions (6% employee and 8.4% employer) with company headcount, continuous-service and opt-in checks.
- Effective-dated rule version recorded on payroll runs and payslips.

## Statutory payroll boundary
See [PNG statutory payroll rules](PNG_PAYROLL_STATUTORY_RULES.md). Core rates are taken from the Income Tax Act 2025 and BPNG superannuation notice. This is not a certification of complete PNG payroll compliance. Salary packaging, non-cash benefits, redundancy, long service leave, exemptions, final pay and statutory remittance returns need additional implementation and adviser review.

## Acceptance checklist
- [ ] Clean PostgreSQL migrations succeed and rollback safely.
- [ ] Company A cannot read or mutate Company B HR/payroll data.
- [ ] Employee number uniqueness is enforced within a company.
- [ ] Leave applicant cannot approve their own application.
- [ ] PAYE resident and non-resident bands match the Income Tax Act 2025.
- [ ] No employment declaration uses the maximum statutory withholding rate.
- [ ] Approved dependant tax credit is capped and applied per fortnight.
- [ ] Superannuation eligibility, base and contribution rates match BPNG requirements.
- [ ] Payroll creator cannot approve their own run.
- [ ] Payslips are inaccessible until approval and across company boundaries.
- [ ] Payroll adviser verifies weekly proration, employee declarations, exemptions and all omitted statutory cases before production.
- [ ] Backup/restore and security review completed before production.
