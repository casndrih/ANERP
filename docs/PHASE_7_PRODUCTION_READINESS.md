# Phase 7 — Production readiness and security hardening

## Objective
Establish repeatable security, operational and acceptance controls before ANERP is used for live business records. This phase does not certify the application as secure and does not replace an independent penetration test, accountant review or PNG legal/payroll advice.

## Implemented in this increment
- Central company-role permission matrix with deny-by-default behavior for unknown roles and permissions.
- Permission enforcement on product management, procurement create/submit/approve/receive, finance access/posting/reconciliation, and payroll access/create/approval.
- Append-only audit events for authenticated company mutations and report exports, including actor, active company, route, outcome, HTTP status, safe route record identifiers and source IP. Request bodies and secrets are deliberately not captured.
- PostgreSQL triggers reject UPDATE and DELETE against audit_events, protecting event history from ordinary application/database statements. Database superusers remain trusted administrators and can bypass/alter database protections.
- Unit tests for role/permission separation, deny-by-default behavior and RFC-compatible TOTP generation.
- PostgreSQL-backed feature tests for cross-company inventory visibility, tenant-bound product creation, unauthorized company selection and inactive-company selection.
- Additional PostgreSQL-backed tests verify finance journals, payroll runs and procurement orders are isolated to the active company, finance CSV exports exclude another company's ledger rows, and cross-company posting/approval is denied.
- Branch-level feature tests cover HR employee and leave access, payroll report aggregation, warehouse visibility, procurement orders, and branch-scoped finance access and report aggregation.
- Company and user administration: single-use email invitations, tenant-scoped role and branch assignments, suspension/reactivation, invitation revocation, last-owner safeguards and company-scoped audit history.
- TOTP authenticator MFA with encrypted secrets, six-digit time-based codes, one-time hashed recovery codes, and password-plus-code disable flow for non-privileged users. Privileged company roles must enroll before workspace access and cannot disable MFA themselves.

## Scope
- Automated dependency and container vulnerability checks in GitHub Actions.
- Secret scanning on pull requests and pushes.
- Deployment-readiness and environment separation checklist.
- Backup, encryption, retention and restore runbook.
- Tenant-isolation, authorization and maker-checker test plan.
- Operational monitoring, incident response and recovery objectives.
- Documented go-live acceptance and sign-off.

## Security requirements before production
1. Terminate TLS at a maintained reverse proxy; redirect HTTP to HTTPS.
2. Restrict the application, PostgreSQL and Redis to trusted networks. Never publish database/cache ports.
3. Use unique production secrets; keep .env and credentials out of source control; rotate any exposed credentials.
4. Set production APP_ENV=production, APP_DEBUG=false, secure session cookies and trusted proxy settings.
5. MFA is enforced for privileged company roles; confirm the policy and recovery procedure during UAT before external access is enabled.
6. Apply least-privilege roles and verify each controller, export and object route checks the active company and record ownership.
7. Require independent approval for sensitive postings and retain immutable audit events.
8. Patch the guest OS, Docker engine, base images, PHP/Laravel dependencies and extensions.
9. Encrypt backups before off-host transfer and restrict restore access.
10. Monitor authentication failures, application errors, database health, storage, backup completion and certificate expiry.

## Required automated tests
- User from company A cannot read, update, delete, approve, export or infer company B records by changing route IDs or request parameters.
- Inactive membership and inactive company access are rejected.
- Branch-scoped users cannot access another branch where a workflow is branch-restricted.
- A maker cannot approve their own purchase order, payroll run or journal.
- Draft journals are excluded from reports and posted records cannot be altered through ordinary update routes.
- Exports use the same authorization and company filters as on-screen reports.
- Validation, CSRF, login throttling and session invalidation are verified.
- Backup restore reproduces a usable database and uploaded files in an isolated environment.

## Branch-level access boundaries
- Company membership `branch_id` is now enforced for HR employee/leave/attendance data, payroll employee lines and payslips, warehouse inventory balances and movements, and purchase orders/receiving.
- Branch-restricted payroll users can calculate branch-only draft runs but cannot approve a company-wide payroll run; an unscoped payroll approver is required.
- Finance records now carry nullable branch attribution on journals, supplier bills, customer invoices, bank accounts, bank transactions and reconciliations. Branch-restricted finance users can work only with records attributed to their assigned branch; legacy records with no branch remain visible only to company-wide memberships. Company-wide users may create either company-wide or branch-attributed entries.
- Company-wide memberships (`branch_id` is null) retain company-level access.

## Automated accounting integrations
- Payroll approval creates balanced accrual journals per employee branch: gross wages and employer super expense are debited; PAYE, employee super, employer super and net wages payable are credited. This is an accrual only; payment and remittance are not executed.
- Inventory movements create balanced journals using recorded unit cost: opening stock, purchase receipts, issues and positive/negative adjustments. Cross-branch transfers use branch-level clearing journals; same-branch transfers do not change total inventory value.
- Payroll runs and stock movements have unique links to generated journals to prevent duplicate posting.
- Historical payroll and stock movements are not backfilled. Inventory costing remains recorded-cost based, not FIFO or weighted average.
- Accountant review is required for opening-equity, GRNI, inventory-adjustment, inter-branch clearing and payroll-liability mappings. Inventory adjustment approval and payroll payment/remittance remain outstanding.

## Go-live gates
- [ ] All CI tests pass on the exact release commit.
- [ ] Dependency and container scans have no unaccepted critical/high findings.
- [ ] Independent security review and penetration test completed; findings triaged.
- [ ] Tenant-isolation and authorization tests pass, including branch-level tests.
- [ ] Audit-event append-only migration is applied and verified on the production PostgreSQL version.
- [x] TOTP MFA enforcement for privileged company roles implemented; production enrollment and recovery drill still required.
- [ ] TLS, firewall/VPN and private database/cache networking verified.
- [ ] Encrypted off-host backup configured and restore drill witnessed.
- [ ] Monitoring, alerting, incident contacts and escalation documented.
- [ ] Payroll calculations reviewed against current IRC guidance and real sample cases.
- [ ] Accounting reports and posting controls reviewed by a PNG-qualified accounting professional.
- [ ] Business owner signs UAT and accepts known limitations.

## Explicitly not certified by this phase
Passing CI or vulnerability scanners is not a penetration test, compliance certification, audit opinion, disaster-recovery guarantee or permission to process live payroll/accounting records. Do not expose the system publicly until every applicable go-live gate has an owner and evidence.