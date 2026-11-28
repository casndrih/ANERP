# Phase 6 — Reporting, analytics and business intelligence

## Included
- Company-scoped trial balance from posted journal lines.
- Profit and loss for a selected date range.
- Balance sheet as at the selected end date, with accumulated earnings calculated from posted income and expense accounts.
- Bank-linked GL cash movement for a selected period.
- Accounts receivable and payable ageing by due date.
- Approved payroll run summaries: gross, PAYE, employee/employer super and net pay.
- Inventory movement quantities and recorded movement costs by product and warehouse.
- CSV export for all report types, with date range metadata.
- Active company context enforced; no arbitrary company selector or ID is accepted.

## Definitions and limitations
- Draft journals are excluded.
- Trial balance and balance sheet include posted entries through the selected end date; profit/loss uses the selected period.
- Balance sheet earnings are calculated from posted income and expense entries; year-end close journals are not generated.
- Bank cash movement is not a classified operating/investing/financing cash-flow statement.
- AP/AR ageing uses full posted bill/invoice amounts because receipts, payment allocation, settlements, credits and refunds are not yet available.
- Payroll includes approved runs only.
- Inventory reports quantities and recorded unit costs; no FIFO or weighted-average valuation is claimed.
- Finance reports filter journal and source documents by branch attribution for branch-restricted users; payroll filters employee lines and inventory filters warehouse movements. Unassigned legacy finance records remain company-wide only.
- CSV only; XLSX/PDF and scheduled distribution remain future work.
- These are management reports, not audited financial statements or statutory filings.

## Acceptance checklist
- [ ] Verify report totals against controlled journal fixtures.
- [ ] Confirm draft journals are excluded.
- [ ] Confirm cross-company data cannot appear in reports or exports.
- [ ] Verify date boundaries and timezone behavior.
- [ ] Verify AP/AR ageing buckets and payroll aggregates.
- [x] Add pure unit coverage for debit/credit balances, ageing boundaries, signed stock movements/transfers and net income calculations.
- [ ] Verify stock movement signs and paired transfers against database fixtures.
- [ ] Accountant review of statement presentation and opening balances.
- [ ] Add automated feature tests and security review before production.
