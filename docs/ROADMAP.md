# ANERP roadmap

ANERP is being delivered through sequential feature branches and pull requests. The authoritative phase-by-phase backlog, acceptance gates and evidence register is [PHASE_COMPLETION_AUDIT.md](PHASE_COMPLETION_AUDIT.md). A phase is not complete merely because its container builds or a subset of CI tests passes.

1. **Foundation:** Laravel application, PNG defaults, dashboard, PostgreSQL/container configuration and ESXi deployment documentation. Remaining: repeatable clean install/upgrade, validated ESXi deployment and OVA appliance build/import evidence.
2. **Authentication and tenancy:** session authentication, company membership, invitations, permissions, MFA, audit trail and tenant/branch isolation. Remaining: password recovery/account lifecycle verification and complete route-by-route authorization acceptance.
3. **Inventory and procurement foundation:** products, suppliers, warehouses, stock ledger, transfers, purchase orders and receiving. Later phases add valuation, counts, invoice matching and landed costs. Remaining: controlled opening-stock conversion, replenishment, barcode, batch/serial/expiry tracking and full operational UAT.
4. **HR and payroll:** employee directory, attendance, leave, payroll runs, payslips, PNG PAYE and core superannuation. Remaining: statutory edge cases, payment/remittance, entitlement accruals, annual reporting and adviser sign-off.
5. **Finance and accounting:** double-entry GL, AP/AR, supplier/customer settlement, credit notes, refunds, GST calculations and bank reconciliation. Remaining: fixed assets, budgets/cost centres, supplier advances, multi-currency/FX, payment reversals, bank integrations, period/year-end close and complete statutory GST filing.
6. **Reporting and analytics:** trial balance, P&L, balance sheet, bank movement, AP/AR ageing, payroll and inventory reports with CSV export. Remaining: XLSX/PDF, classified cash flow, scheduled distribution, dashboards/drill-through and full report acceptance.
7. **Production readiness:** role/permission matrix, audit events, MFA, security scanning and backup/restore runbook. Remaining: penetration test, production network/TLS review, monitoring, witnessed restore and disaster-recovery drills, load testing and formal business/accounting/payroll acceptance.
8. **Inventory valuation:** FIFO, moving weighted average, warehouse/product cost balances, stock-count sessions and independent adjustments. Remaining: batch/serial/expiry costing, opening-cost conversion, cutover rehearsal and stock/GL reconciliation.
9. **Procurement controls:** multi-line POs, three-way invoice matching, GRNI-to-AP, landed-cost allocation, supplier payments/credit notes/returns and import assessment capture. Remaining: filing-ready GST, Section 65A notice/remittance workflow, input-tax apportionment, GST adjustment notes, OCR, verified tariff classification, charge-specific allocation and tolerance approvals.
10. **Sales, POS and customer receivables:** sales orders, stock reservation/fulfilment, POS till sessions, receipts, advances, credit notes, item-level returns, customer credit limits/holds, maker-checker cash refunds and POS settlement voids. Remaining: dispatch/POD, EFTPOS and settlement matching, customer statements/reminders, price lists/discount approvals, multi-currency AR, receipt/advance reversals and complete GST adjustment notes.

## Delivery and release policy

- Work on the latest phase branch in the sequential PR chain; do not silently bypass earlier phases.
- Run the full test suite, migration checks, tenant/branch security tests, container build and security workflows on the exact proposed commit.
- Never disable meaningful tests or weaken assertions just to obtain a green build.
- Update the phase document and completion audit whenever scope changes or an item is completed.
- Merge only after the pull request has been reviewed and all applicable acceptance gates have evidence.
- Until the ESXi deployment, security, statutory and user-acceptance gates are evidenced, ANERP remains an implementation candidate—not a production-certified ERP.
