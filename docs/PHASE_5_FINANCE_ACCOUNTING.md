# Phase 5 — Finance and accounting

## Included
- Company-scoped chart of accounts with a PNG-oriented starter chart and normal debit/credit balances.
- Balanced manual journal creation, draft review and maker-checker posting.
- Supplier bill drafts and posting to expense / accounts payable.
- Customer invoice drafts and posting to accounts receivable / revenue.
- Bank account records linked to general-ledger cash accounts.
- Bank transaction capture with balanced journal drafts.
- Bank statement reconciliation that accepts only posted transactions and checks opening balance plus selected net movement against statement closing balance.
- Company-level tenant filtering and PGK defaults.

## Accounting controls
- Journal debits and credits must balance to the cent.
- Each journal line must be either debit or credit, never both.
- A journal/document creator cannot post their own entry.
- Posted journals and source documents are immutable through this phase's UI.
- Account, supplier, customer, bank account and document references are validated within the active company.

## Not included yet
- GST calculations and GST return filing.
- Customer receipts, supplier payment allocation, credit notes, refunds and ageing automation.
- Inventory valuation journal automation and payroll-to-ledger posting.
- Fixed assets, depreciation, budgets, cost centres, multi-currency and foreign exchange revaluation.
- Period locks, year-end closing, retained earnings roll-forward and full financial statements.
- Bank feed/API integrations and statement import formats.
- Automated tenant-isolation tests, accounting review and external audit.

## Acceptance checklist
- [ ] Clean PostgreSQL migrations and rollback succeed.
- [ ] Default chart seeding is idempotent for each company.
- [ ] Company A cannot access Company B accounts, journals, customers, suppliers, bills, invoices or bank transactions.
- [ ] Journals reject unbalanced totals, zero totals and lines containing both debit and credit.
- [ ] Journal creator cannot post their own journal.
- [ ] Posted journals cannot be edited or posted again.
- [ ] Supplier bills post debit expense and credit AP control.
- [ ] Customer invoices post debit AR control and credit revenue.
- [ ] Document creators cannot post their own bills or invoices.
- [ ] Bank transactions create balanced draft journals and cannot be reconciled until posted.
- [ ] Bank reconciliation rejects cross-company, duplicate, already reconciled and out-of-period transactions.
- [ ] Reconciliation closing balance equals opening balance plus selected net transactions.
- [ ] Accountant reviews opening balances, GST treatment, account mappings and period close before production.
