# Phase 3 acceptance checklist

- [ ] Migrations apply successfully to a clean PostgreSQL database.
- [ ] Company A cannot view Company B warehouses, suppliers, products, orders or stock movements.
- [ ] A user without an allowed inventory role cannot post stock or transfer stock.
- [ ] Opening balance posts a positive ledger entry.
- [ ] Issue and adjustment-out reject quantities above on-hand.
- [ ] Inter-warehouse transfer creates paired transfer-out/transfer-in entries with one transfer reference.
- [ ] Transfer failure rolls back both ledger entries and the transfer header.
- [ ] Purchase order can be drafted and submitted.
- [ ] Purchase order creator cannot approve their own order.
- [ ] Only authorized approver roles can approve.
- [ ] Unapproved purchase orders cannot be received.
- [ ] Partial receipts update received quantity and stock on-hand.
- [ ] Over-receipt is rejected.
- [ ] Fully received purchase order changes to received status.
- [ ] Purchase-order and manual movement references are visible for audit review.
- [ ] Backup and restore test is completed before production.
