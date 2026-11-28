# Phase 7 — Inventory-to-general-ledger integration

## Included
- Inventory movements automatically create posted, balanced GL journals using the recorded movement unit cost.
- Opening stock: debit Inventory (1200), credit Opening equity (3000).
- Purchase receipts: debit Inventory (1200), credit Goods received not invoiced (2600).
- Issues: debit Cost of goods sold (5000), credit Inventory (1200).
- Positive adjustments: debit Inventory (1200), credit Inventory adjustment gains (4200).
- Negative adjustments: debit Inventory shrinkage expense (6500), credit Inventory (1200).
- Inter-branch transfers create one balanced journal per branch using the Inter-branch inventory clearing account (2700); same-branch transfers do not change company inventory value.
- A unique movement-to-journal link prevents duplicate posting.

## Valuation and accounting boundaries
- This phase posts each movement's recorded unit cost; it does not calculate FIFO, weighted-average or landed cost. Users must enter and review the cost basis.
- Purchase receipts credit goods-received-not-invoiced. Later phases implement three-way supplier invoice matching, GRNI-to-AP posting, supplier payments and supplier stock-return credit notes; reconcile these workflows against the inventory and GL ledgers.
- Opening stock credits opening equity; an accountant must confirm the opening-balance counterpart before production migration.
- Cross-branch transfers use an inter-branch clearing account and must be reviewed in branch and consolidated reports.
- Automatic inventory journals are system-generated operational postings. Stock adjustments still need a separate approval workflow and audit review before production use.
- Existing historical movements are not backfilled automatically. Do not run an opening-stock migration without an approved valuation and cutover plan.

## Acceptance checklist
- [ ] Reconcile each movement journal to the stock ledger quantity and recorded cost.
- [ ] Validate purchase receipt clearing against supplier invoices and GRNI ageing.
- [ ] Verify cross-branch clearing nets to zero in consolidated reporting.
- [ ] Accountant approves opening equity, adjustment gain/shrinkage and cost-of-goods-sold mappings.
- [ ] Confirm inventory costing policy (FIFO or weighted average) before enabling valuation-based statutory reporting.
