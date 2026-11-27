# Phase 4 — HR and payroll

## Included
- Company-scoped employee directory with unique employee numbers, branch, department, job title, employment type, hire date, salary and pay frequency.
- Leave types and leave applications with HR approval and creator self-approval prevention.
- Daily attendance entries with one record per employee and work date.
- Draft payroll generation for active employees using configured base salary.
- Maker-checker payroll approval and approved-run payslip views.
- PGK currency defaults and effective-dated, company-scoped payroll rule storage.

## Payroll safety boundary
This phase intentionally does **not** calculate PNG PAYE or employee/employer superannuation. Payroll rules are stored as configurable, effective-dated records with verification metadata, but no statutory rule is enabled by default. Official rates, thresholds, caps, exemptions, rounding, residency and effective dates must be checked against current IRC/IRD and superannuation regulator/provider requirements and approved by a qualified PNG payroll adviser before statutory deductions or production payroll are enabled. Do not treat base-pay-only output as a compliant payslip.

## Not included yet
- Leave accrual/balances, holiday calendars, shift rosters, overtime and timesheet-to-payroll integration.
- Bank file generation, payment execution, payroll journal posting and statutory returns.
- MFA, granular RBAC administration, full audit coverage and independent tenant-isolation/security tests.

## Acceptance checklist
- [ ] Clean PostgreSQL migrations succeed and rollback safely.
- [ ] Company A cannot read or mutate Company B employees, leave, attendance or payroll.
- [ ] Employee number uniqueness is enforced within a company.
- [ ] Invalid or cross-company branch references are rejected.
- [ ] Leave dates, employee and leave type are validated within the active company.
- [ ] Leave applicant cannot approve their own application.
- [ ] Attendance duplicate employee/date is updated rather than duplicated.
- [ ] Payroll includes active employees from the selected company only.
- [ ] Payroll creator cannot approve their own run.
- [ ] Payslips are inaccessible until approval and across company boundaries.
- [ ] PNG statutory payroll rules remain disabled until independently verified and approved.
- [ ] Backup/restore and security review completed before production.
