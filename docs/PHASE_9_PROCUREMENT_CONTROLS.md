# Phase 9 — Procurement and supplier invoice controls

## Delivered in this increment
- Supplier bills can be linked to an approved purchase order and classified as goods invoices or landed-cost invoices.
- Goods invoices require invoice lines to identify the purchase-order line, billed quantity and unit price.
- A goods invoice cannot exceed received, not-yet-invoiced quantity for a PO line.
- Goods invoice unit prices must match the approved PO price within K0.01; matched line totals reconcile to the invoice net amount. Eligible GST is posted separately to GST input tax, while gross AP includes the GST amount.
- Pending and approved invoice matches are counted when preventing duplicate billing, including invoices that have been matched but not yet posted.
- Goods invoice approval clears goods-received-not-invoiced (GRNI) into accounts payable through a balanced posted journal.
- Landed-cost invoices can be allocated across received stock by received line value, received quantity, equal share, product weight or product volume per received product line; the selected basis is persisted for audit.
- The allocation capitalises the share represented by on-hand inventory and expenses the consumed share to cost of goods sold. The allocation basis is selected by the independent approver and stored on each allocation record.
- FIFO remaining layers and weighted-average inventory balances are updated when landed costs are capitalised.
- Invoice creator and invoice matcher cannot approve their own invoice. The approval action requires the dedicated procurement.invoice.approve permission, granted to procurement approvers, finance managers and accountants; owners/admins retain all permissions.
- Direct posting of a supplier bill linked to a purchase order is blocked in the general finance bill workflow. It must pass through matching approval.
- Domestic PNG GST is calculated on general supplier bills and customer invoices using the 10% standard rate, zero-rated, exempt and out-of-scope treatments. The company GST registration flag controls output GST and input-credit eligibility; supplier registration number plus tax invoice reference are required before input GST is posted to the GST input control account.
- General supplier bill posting separates net expense and eligible input GST from gross AP; customer invoice posting separates net revenue and output GST from gross AR. A G1-style management summary reports domestic output GST, eligible domestic input credits and the net difference.

## Landed-cost safety guard
Landed-cost allocation is deliberately conservative. It blocks when the selected product/warehouse stock pool includes other inbound sources or inter-warehouse transfers, or when FIFO layers do not reconcile to on-hand stock. In those cases, the stock controller must reconcile the affected cost pool before allocating freight. This avoids silently applying the charge to unrelated stock layers.

## Accounting behavior
- Goods invoice: debit GRNI (2600), credit accounts payable (2000).
- Landed-cost invoice: debit inventory (1200) for the remaining-stock portion, debit COGS (5000) for the consumed portion, and credit accounts payable (2000) for the invoice total.
- All journals are posted in the supplier bill's branch and retain the bill's creator and the independent approver.

## Current limitations
- Purchase orders now support multiple product lines in the authoring screen and the controller validates each submitted line.
- Full statutory GST return automation and tax adjustment notes, import GST/customs assessment postings, withholding under section 65A, private-use and exempt-sale input-credit adjustments, inventory/GRNI supplier debit/credit notes, invoice attachments/OCR and payment reversals remain outstanding. The GST summary is a management estimate, not a filing-ready G1 return. Supplier payment allocation is implemented. Invoice-level supplier credit notes are supported for posted general expense bills only; matched inventory/GRNI bills are deliberately blocked until stock-return accounting exists.
- Goods price variance beyond K0.01 is blocked for manual resolution; tolerance approval workflows are not yet available.
- Landed costs are only capitalised when the product/warehouse cost pool is isolated and reconciled. Mixed stock pools and transferred stock require manual reconciliation before this feature can proceed.
- Landed costs support received line value, received quantity, equal-share, product weight and product volume allocation. Charge-specific allocation rules and customs-specific landed cost allocation are not yet implemented.
- Supplier invoice matching is not an external tax or statutory compliance certification.

## Acceptance checks
- Verify standard 10%, zero-rated, exempt and out-of-scope domestic GST treatments; verify company GST registration, supplier registration evidence and tax-invoice-reference gates for input credits. Import GST base calculation is available as a calculator only and is not yet integrated into accounting postings.
- Create and submit a purchase order with multiple distinct product lines; verify each line is independently received and matched.
- Apply migrations against a clean PostgreSQL database and run the complete test suite.
- Confirm a bill cannot be matched to another company's PO or another supplier's PO.
- Confirm invoice quantity cannot exceed received quantity less all matched/posted invoices.
- Confirm price and total variances block matching.
- Confirm invoice creator/matcher cannot approve their own invoice.
- Confirm goods invoice approval clears GRNI to AP.
- Confirm landed cost is split between inventory and COGS based on remaining stock.
- Verify supplier payment drafts allocate only to posted bills for the same supplier and branch, prevent overpayment, enforce maker-checker, create a balanced AP/bank journal and outgoing bank transaction, and reduce AP ageing.
- Verify supplier credit notes are limited to posted PGK general expense bills, cannot exceed outstanding AP after payments/other credits, enforce maker-checker, reverse the original expense and AP, and reduce AP ageing. Inventory/GRNI bills must be rejected.
- Confirm mixed inbound sources and transfer history block unsafe landed-cost capitalisation.
- Review all postings and stock valuation effects with an accountant before live use.

This phase is a draft implementation candidate. It is not merged or deployed and must pass CI, migration tests, stock reconciliation and accountant review before production use.


Official GST references used for the current calculator and posting scope:
- PNG Goods and Services Tax Act 2003, sections 6–9 and 30–31: https://www.paclii.org/pg/legis/consol_act/gasta2003226.pdf
- IRC G1 GST return notes: https://static.irc.gov.pg/2021/December/h8qIml-media-2018-05-01-G1-Goods-Services-Tax-Return.pdf
- IRC GST Section 65A fact sheet: https://static.irc.gov.pg/2021/December/5ExWcL-media-GST-Section-65A-Fact-Sheet.pdf
