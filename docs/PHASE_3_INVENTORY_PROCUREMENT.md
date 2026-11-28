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

## Known limitations
- The purchase order entry screen currently creates one product line per order. Multi-line PO editing, tax codes, discounts and freight allocation are planned.
- Phase 8 adds company-configurable FIFO and moving weighted-average costing, per-warehouse/product cost balances, FIFO layers and exact movement total values. Historical GL journals are not rewritten when a method is selected.
- Purchase receipts create GRNI journals. Phase 9 adds supplier invoice matching and GRNI-to-AP posting; supplier payment allocation remains future work.
- Formal stock-count sessions, adjustment maker-checker approval, supplier payments, reorder rules, barcode scanning and batch/serial tracking remain future work.
- Role codes are checked in controller actions, but a complete permissions administration interface and policy test suite are still required.
- Opening balance is currently an operational stock-in movement; production cutover needs a controlled opening-stock import and reconciliation.
- Manual adjustment reasons are stored as notes; a formal stock-adjustment approval process remains outstanding.

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
