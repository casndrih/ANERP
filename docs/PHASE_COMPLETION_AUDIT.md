# ANERP phase completion audit — 28 November 2026

This is the working completion register for Phases 1–10. A green workflow proves only the code and checks exercised by that workflow; it does not prove every phase requirement is complete. A phase is complete only when its documented scope is implemented, migrations pass on a clean PostgreSQL database, feature/security/tenant tests pass, operational evidence exists where applicable, and the phase pull request is reviewed and merged.

## Repository state at audit time
- Repository: `casndrih/ANERP`
- Default branch: `main` (`cbd3dc5` at the time of this audit).
- Ten feature branches exist. Pull requests #1–#9 are open; PR #9 is Phase 10 and targets Phase 9. Phase 10 credit-control work is being added to its branch.
- Phase 2 branch head `dd04175`: CI and container build passed.
- Phase 3 branch head `33422fe`: CI and container build passed.
- Phase 4 branch head `fe44b1b`: CI and container build passed.
- Phase 5 branch head `6abe844`: CI and container build passed.
- Phase 6 branch head `86937e8`: CI and container build passed.
- Phase 7 branch head `c3657f8`: CI, container build and security checks passed.
- Phase 8 branch head `8854f38`: CI, container build and security checks passed.
- Phase 9 latest tested commit `7c62480`: CI 79 tests / 353 assertions passed, security checks passed, container build passed.
- Phase 10 item-level sales-return implementation commit `41ae178`: CI 100 tests / 555 assertions passed, including three sales-return feature tests; the matching container build passed. The docs-only branch head `4dbf153` also passed CI and container build.
- Phase 1 PR #10 targets `main`; PR #1 (Phase 2) targets Phase 1. Phase 2 was synchronized with Phase 1 through merge commit `6179073` so the PR chain is no longer diverged. Phase 1 branch head `0cb0c39` passed two complete foundation workflows: clean Compose install, fresh migrations, backup-first upgrade, HTTP `/up` health and mocked OVF-to-OVA packaging. Real ESXi export/import, production restore and rollback drills remain unverified without the target host.
- Older failed runs on earlier Phase 9/10 commits are historical and are not the latest branch results.

## Phase-by-phase outstanding register

### Phase 1 — Foundation
**Delivered:** Laravel application foundation, PNG defaults, multi-stage PHP-FPM/Nginx images, Docker Compose health dependencies, secure first-install bootstrap, backup-first upgrade (PostgreSQL, storage and .env), OVA packaging/checksum script, 128 GB ESXi VM profile and automated clean-install/migration/upgrade/HTTP/OVA-packaging checks. Phase 1 head `0cb0c39` passed the full foundation workflow twice.

**Still required:** real OVA export from the target ESXi/vCenter, import/boot/network/persistence verification on that host, witnessed database/storage restore and rollback drill, workload-based CPU/storage sizing and production HTTPS/firewall validation. No production appliance is certified.

### Phase 2 — Authentication and company tenancy
**Delivered across Phase 2 and later hardening:** session authentication, company membership selection, invitation/role administration, MFA for privileged roles, permission checks, audit events and multiple tenant/branch isolation tests.

**Still required:** reconcile the Phase 2 document with the later delivered features; verify password reset/recovery and account lifecycle flows; complete route-by-route tenant and branch authorization coverage; verify session revocation, MFA recovery and invitation expiry/replay behavior; run the Phase 2 acceptance checklist on the latest integrated branch; review rate limits and security headers.

### Phase 3 — Inventory, warehouses and procurement foundation
**Delivered across Phases 3, 8, 9 and 10:** product and supplier records, warehouses, stock ledger, transfers, purchase orders and receiving, valuation, stock counts, invoice matching and landed costs.

**Still required:** controlled opening-stock import with reconciliation and audit trail; reorder points/replenishment; barcode scanning; batch/lot, serial and expiry tracking; stock reservation and movement concurrency review across all workflows; purchase-price tolerance approval; operational stocktake and warehouse UAT. Update stale Phase 3 limitations that later phases have already resolved.

### Phase 4 — HR and payroll
**Delivered:** employee records, attendance, leave, payroll runs, payslips, maker-checker approval, PNG PAYE and core superannuation calculations.

**Still required:** complete the unchecked acceptance checklist with automated evidence; statutory edge cases and adviser review (non-cash benefits, salary packaging, redundancy, long-service leave, final pay, exemptions, special industries and residency/declaration cases); payroll payment and remittance; leave accrual/entitlement rules and balance reconciliation; payroll reversals/amendments; bank payment file/export; year-to-date and annual statutory reports; HR/payroll UAT and accountant/payroll-adviser sign-off.

### Phase 5 — Finance and accounting
**Delivered across later phases:** double-entry journals, AP/AR, supplier/customer payments and receipts, credit notes, refunds, GST calculations and management summaries, bank reconciliation, inventory/payroll journal integrations.

**Still required:** fixed-asset register, depreciation and disposal; budgets and cost centres; period locks and controlled period close; year-end close and retained-earnings roll-forward; supplier advances; multi-currency and FX revaluation; bank statement import and bank-feed/API adapters; payment reversals; full statutory GST return and adjustment workflows; clean migration/rollback and accountant sign-off. Refresh the Phase 5 document to include features delivered later.

### Phase 6 — Reporting and analytics
**Delivered:** trial balance, profit and loss, balance sheet, bank cash movement, AP/AR ageing, payroll summaries, inventory movement reports and CSV export.

**Still required:** verify every report against controlled fixtures and the general ledger; XLSX/PDF exports; classified operating/investing/financing cash-flow statement; report scheduling/distribution; saved report filters; dashboard KPIs and drill-through; consolidated multi-company reporting where authorised; API/webhooks and integration reporting; timezone/date-boundary tests and accountant sign-off. Update stale statements about receipts/payments and valuation.

### Phase 7 — Production readiness and security
**Delivered:** permission matrix, audit logging, MFA, secret/dependency/container scanning, backup/restore runbook and tenant/branch tests.

**Still required:** latest-branch CI and security evidence; independent penetration test and remediation; TLS/firewall/private database/cache verification; production secret rotation and least-privilege deployment; monitoring/alerting and incident response; encrypted off-host backups and witnessed restore drill; RPO/RTO validation and disaster-recovery exercise; load/performance tests; vulnerability triage; payroll/accounting compliance reviews; signed business UAT. Passing CI or scans is not production certification.

### Phase 8 — Inventory valuation and stock-count controls
**Delivered:** FIFO and moving weighted-average valuation, cost balances/layers, stock-count sessions and independent adjustment approval.

**Still required:** batch/lot/serial/expiry costing; per-layer FIFO issue-consumption provenance and exact FIFO layer restoration on customer sales returns (current partial returns use weighted unit cost of the original issue movement); opening-cost and stock conversion tooling; historical stock/GL reconciliation tooling; valuation replay and cutover rehearsal on representative data; concurrency and negative-stock stress tests; mixed inbound/transfer pool resolution workflow; accountant/stock-controller sign-off.

### Phase 9 — Procurement controls
**Delivered:** PO multi-line authoring, three-way invoice matching, GRNI-to-AP, landed-cost allocation, supplier payments/credit notes/stock returns and import assessment capture.

**Still required:** statutory G1 filing automation and validation; Section 65A notice lifecycle, supplier scope, effective/revocation dates and remittance forms; private-use and exempt-sale input-tax apportionment; GST adjustment/debit notes; invoice document attachments and OCR; verified tariff/HS classification; charge-specific landed-cost allocation; purchase price/quantity tolerance approvals; payment reversals; robust mixed-stock and transfer reconciliation; tax/accountant review. Current GST summary remains a management tool, not a filing-ready return.

### Phase 10 — Sales, POS and customer receivables
**Delivered:** sales orders, stock reservation/fulfilment, POS till sessions, customer receipts and allocations, advances, credit notes, AR ageing, customer credit limits/holds, item-level sales returns, POS cash refunds and POS transaction voids. Sales returns capture invoice-line quantities, condition and receiving warehouse; draft reservations prevent over-return; maker-checker posting creates GST-adjusted credit notes, reduces AR or credits customer deposits, and restores resellable inventory at the original issue movement's weighted unit cost. POS cash refunds are capped by source deposit and original POS sale, post a balanced customer-deposit/cash journal and reduce expected till cash. POS voids reverse the cash receipt, reopen AR and are blocked by subsequent settlements/credits/returns/refunds. CI passed 103 tests / 597 assertions on `6af4f2b`; the latest cash-workflow changes were still running through CI/container build.

**Still required:** item-level sales returns with stock/valuation/GST reversal; POS refunds and voids; picking/packing, dispatch manifests and proof of delivery; EFTPOS integration and end-of-day settlement; customer statements and automated reminders; price lists and discount approval; multi-currency AR; receipt reversals and advance reallocation; complete GST debit/adjustment-note workflow; feature tests for credit exposure and offset behavior.

## Completion gates for every phase
- [ ] Scope and known limitations reconciled against the code; no stale documentation.
- [ ] Every migration applies and rolls back on a clean PostgreSQL database.
- [ ] Feature, unit, tenant-isolation, branch-access and authorization tests pass on the exact phase head.
- [ ] Container build and security workflows pass on the exact phase head.
- [ ] No meaningful tests are disabled or weakened to obtain a green run.
- [ ] User-visible workflows, permissions, audit trail, validation and error handling are complete.
- [ ] Operational and statutory evidence/reviews are attached where required.
- [ ] Pull request reviewed, dependency chain resolved, and merged only after acceptance.

## Working rule
Keep this register updated as each item is implemented and verified. Do not mark a phase complete based only on a successful build or a small subset of passing tests. Do not merge an unreviewed phase branch or claim the platform is production-ready while any applicable completion gate remains open.
