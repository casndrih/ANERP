# Phase 9 — Procurement and supplier invoice controls

## Delivered in this increment
- Supplier bills can be linked to an approved purchase order and classified as goods invoices or landed-cost invoices.
- Goods invoices require invoice lines to identify the purchase-order line, billed quantity and unit price.
- A goods invoice cannot exceed received, not-yet-invoiced quantity for a PO line.
- Goods invoice unit prices must match the approved PO price within K0.01; total invoice value must reconcile to matched line totals. Tax, freight and other charges must be entered separately until tax handling is implemented.
- Pending and approved invoice matches are counted when preventing duplicate billing, including invoices that have been matched but not yet posted.
- Goods invoice approval clears goods-received-not-invoiced (GRNI) into accounts payable through a balanced posted journal.
- Landed-cost invoices can be allocated across received stock by received line value, received quantity or equal share per received product line; the selected basis is persisted for audit.
- The allocation capitalises the share represented by on-hand inventory and expenses the consumed share to cost of goods sold. The allocation basis is selected by the independent approver and stored on each allocation record.
- FIFO remaining layers and weighted-average inventory balances are updated when landed costs are capitalised.
- Invoice creator and invoice matcher cannot approve their own invoice. The approval action requires the dedicated procurement.invoice.approve permission, granted to procurement approvers, finance managers and accountants; owners/admins retain all permissions.
- Direct posting of a supplier bill linked to a purchase order is blocked in the general finance bill workflow. It must pass through matching approval.

## Landed-cost safety guard
Landed-cost allocation is deliberately conservative. It blocks when the selected product/warehouse stock pool includes other inbound sources or inter-warehouse transfers, or when FIFO layers do not reconcile to on-hand stock. In those cases, the stock controller must reconcile the affected cost pool before allocating freight. This avoids silently applying the charge to unrelated stock layers.

## Accounting behavior
- Goods invoice: debit GRNI (2600), credit accounts payable (2000).
- Landed-cost invoice: debit inventory (1200) for the remaining-stock portion, debit COGS (5000) for the consumed portion, and credit accounts payable (2000) for the invoice total.
- All journals are posted in the supplier bill's branch and retain the bill's creator and the independent approver.

## Current limitations
- Purchase orders now support multiple product lines in the authoring screen and the controller validates each submitted line.
- GST input/output accounting, withholding tax, import-duty calculation from tariff classifications, supplier credit notes, debit notes, invoice attachments/OCR, supplier payment allocation and payment execution are not included.
- Goods price variance beyond K0.01 is blocked for manual resolution; tolerance approval workflows are not yet available.
- Landed costs are only capitalised when the product/warehouse cost pool is isolated and reconciled. Mixed stock pools and transferred stock require manual reconciliation before this feature can proceed.
- Landed costs support received line value, received quantity and equal-share allocation. Weight-, volume- and charge-specific allocation bases are not configurable.
- Supplier invoice matching is not an external tax or statutory compliance certification.

## Acceptance checks
- Verify domestic standard, zero-rated and exempt GST treatments; verify import GST base includes customs value, customs duty, excise and freight/insurance not already included.
- Create and submit a purchase order with multiple distinct product lines; verify each line is independently received and matched.
- Apply migrations against a clean PostgreSQL database and run the complete test suite.
- Confirm a bill cannot be matched to another company's PO or another supplier's PO.
- Confirm invoice quantity cannot exceed received quantity less all matched/posted invoices.
- Confirm price and total variances block matching.
- Confirm invoice creator/matcher cannot approve their own invoice.
- Confirm goods invoice approval clears GRNI to AP.
- Confirm landed cost is split between inventory and COGS based on remaining stock.
- Confirm mixed inbound sources and transfer history block unsafe landed-cost capitalisation.
- Review all postings and stock valuation effects with an accountant before live use.

This phase is a draft implementation candidate. It is not merged or deployed and must pass CI, migration tests, stock reconciliation and accountant review before production use.
