# Phase 8 — Inventory valuation controls

## Implemented in this increment
- Company-level valuation method setting: FIFO or moving weighted average.
- Company owners and administrators can change the method from the stock-control workspace. Branch-restricted users cannot change a company-wide valuation policy.
- Inventory movements are costed before their automatic general-ledger journal is generated.
- FIFO creates receipt/opening/adjustment-in/transfer-in cost layers and consumes the oldest available layers on issues, negative adjustments and transfer-outs.
- Weighted average updates the warehouse/product moving average as quantities are received and issued.
- Exact movement total value is stored separately from rounded unit cost so FIFO issues spanning multiple layers retain cents-accurate journal values.
- Inter-branch transfer-in inherits the source transfer-out total cost; destination cost layers retain a four-decimal blended layer rate.
- Cost balances are maintained per company, warehouse and product.
- On first use, existing movement history is replayed in date/id order to initialise the costing balance. Historical posted journals are not rewritten.

## Valuation behavior
- FIFO issue value is the sum of the oldest available layer quantities multiplied by their layer costs.
- Weighted-average issue value uses the current balance value divided by current quantity, rounded to the currency precision.
- Inbound receipts use the recorded purchase unit cost. Opening balances and manual positive adjustments use the entered cost.
- Negative stock or historical movement sequences that cannot be reconciled block costing rather than silently creating negative or unvalued inventory.
- The valuation method is company-wide, while balances and layers remain warehouse/product scoped.

## Important cutover controls
- Before enabling FIFO or weighted average for a live company, reconcile physical stock, the stock movement ledger, existing inventory GL balances and historical unit-cost snapshots.
- The first valuation replay derives opening layers from existing movement records; it does not import supplier landed costs, freight allocation, batch expiry or serial-level costs.
- Changing valuation method does not rewrite old stock movements or posted GL journals. It changes how future movements are costed after replaying the existing movement history.
- If legacy movements have negative quantities or missing/inconsistent costs, an authorised accountant/stock controller must resolve the discrepancy before automatic issue costing can proceed.
- This increment does not yet implement formal stock-count sessions, adjustment maker-checker approvals, barcode/batch/serial tracking, landed-cost allocation, or supplier invoice three-way matching.

## Tests
- FIFO consumes oldest layers and posts the exact issue value to cost of goods sold.
- Weighted-average issues use the moving average and update remaining inventory value.
- Existing Phase 7 inventory-to-GL tests continue to cover branch journal attribution and balanced postings.

## Production status
This is an implementation increment on a draft feature branch. Run PostgreSQL migrations, the complete CI suite, stock-ledger reconciliation and accountant review before enabling the method for a live company.
