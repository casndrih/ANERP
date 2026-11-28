# Phase 3 — Inventory, Warehousing and Procurement

## Delivered in this branch
- Company-scoped warehouse creation, optional branch assignment and warehouse listing.
- Company-scoped supplier directory and supplier registration.
- Inventory ledger with opening, issue, adjustment-in, adjustment-out, purchase-receipt, transfer-in and transfer-out entries.
- On-hand quantity calculated from the movement ledger by company, product and warehouse.
- Stock issue/adjustment-out and transfer validation to prevent negative on-hand balances.
- Atomic inter-warehouse transfers with a transfer header and paired ledger entries.
- Purchase-order draft, submit, approval, partial receiving and full receiving workflow.
- Maker-checker rule: the creator cannot approve their own purchase order.
- Purchase receipts post to the stock ledger and update received quantities.
- PGK defaults and company-scoped data access throughout the module.

## Current limitations after later phase work
- Multi-line purchase orders, tax treatment, supplier invoice matching, GRNI-to-AP, supplier payments, supplier credit notes, stock returns, FIFO/weighted-average valuation, stock-count sessions and independent adjustment approvals are implemented in later phases.
- Reorder points/replenishment suggestions and purchase planning remain outstanding.
- Barcode scanning, batch/lot, serial-number and expiry tracking remain outstanding.
- Controlled opening-stock import and opening valuation conversion with a reconciliation report are not yet available; an opening movement is not a substitute for a controlled production cutover.
- Purchase-price and quantity tolerance approval workflows are not yet available; mismatches currently require manual resolution.
- Landed-cost capitalisation is deliberately blocked for mixed/unreconciled stock pools and transfers; an operational reconciliation workflow is still needed.
- Complete warehouse/stocktake UAT, concurrency stress tests and stock-to-GL reconciliation evidence are required before production.

## Migration and smoke-test plan
1. Apply migrations against a clean PostgreSQL database.
2. Create a company, owner, branch, warehouse, supplier and stock-tracked product.
3. Post an opening balance and verify warehouse on-hand.
4. Issue less than available stock; verify on-hand reduces.
5. Attempt to issue more than available; expect validation failure and no ledger row.
6. Transfer stock between two warehouses; verify paired entries net to zero company-wide.
7. Create a draft purchase order, submit it, and attempt self-approval; expect 403.
8. Approve from a different authorized user and receive in partial quantities.
9. Verify purchase receipt movements and final received status.
10. Repeat all reads and writes under a second company and confirm there is no cross-company visibility.

This branch is an implementation candidate, not a production-certified inventory or procurement system. Run CI, migration and acceptance checks before merging.
