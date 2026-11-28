# Phase 10 — Sales, POS and customer receivables

## Implemented
- Customer receipts are tenant- and branch-scoped and must use an active bank account belonging to the receipt branch.
- Receipts are created as drafts; the creator cannot post their own receipt.
- A receipt can be allocated across multiple posted invoices for the same customer.
- Partial payments are supported. Posted allocations reduce the invoice's outstanding balance.
- Allocation totals cannot exceed the receipt amount, and a posting-time lock/recheck prevents an invoice from being overpaid by concurrent or later receipts.
- Funds not allocated to invoices are credited to customer deposits and advances (GL account 2500); this supports prepayments without incorrectly reducing AR.
- Posting creates a balanced journal: debit the selected bank GL account, credit accounts receivable for allocations, and credit customer deposits for the unallocated balance.
- Posting also creates an incoming bank transaction linked to the journal so the receipt is available to bank reconciliation.
- Receivables ageing reports show net outstanding invoice balances after posted receipts.
- Supplier payments support drafts, multi-bill allocations, partial bill settlements, maker-checker posting, balanced AP/bank journals, outgoing bank transactions and draft cancellation.
- Customer credit notes support invoice-linked drafts, outstanding-balance controls, independent posting, balanced revenue/AR reversals, cancellation and AR ageing integration. Draft receipts and draft credit notes reserve the same invoice balance.
- Customer deposit refunds support receipt-linked unallocated-balance checks, maker-checker posting, balanced customer-deposit/bank journals, outgoing bank transactions and draft cancellation.
- Sales orders support multi-product draft capture, GST treatment, stock availability checks, approved-order stock reservations, independent approval and fulfilment. Fulfilment issues inventory through the valuation ledger and creates a draft customer invoice with product-level lines.
- POS till sessions support cashier-scoped opening floats, branch/warehouse cash drawers, posted-invoice cash settlement, automatic change calculation, linked customer receipts and bank transactions, manager close/count and expected-versus-counted cash variance.
- Supplier credit notes support posted PGK general expense bills. Supplier inventory credit notes support matched goods bills, return-quantity and on-hand checks, independent posting, FIFO/weighted-average stock issue, GST input-credit reversal, AP reduction and purchase-price variance accounting.
- Domestic PNG GST is posted on general supplier bills, customer invoices and eligible PO-matched goods/landed-cost invoices with company registration settings and supplier registration/tax-invoice evidence for input credits. A G1-style management summary reports sales bases net of output GST.
- Import assessments record actual Customs-assessed duty, excise, customs value and freight/insurance, plus HS code, import declaration, assessment reference and tariff source/verification date. No tariff rate is guessed.
- Accounts payable ageing nets posted supplier payment allocations and posted supplier stock returns; accounts receivable ageing nets posted customer receipts and credit notes.
- Customer credit limits and credit holds are configurable in PGK. Company owners/admins can manage them through the customer credit controls screen. Approval of a sales order and posting of a customer invoice both enforce the policy. Exposure includes posted unpaid invoices and approved unfulfilled sales orders; posted receipt allocations and posted credit notes reduce exposure. An unset credit limit means no numeric limit; a credit hold always blocks new credit commitments.

## Item-level sales returns
- Posted sales-order invoices support draft item-level returns with per-line quantity, condition (resellable/damaged/defective), return reason and receiving warehouse.
- Draft returns reserve invoice-line quantities and gross credit value; cancellation releases those reservations. Posting rechecks remaining quantities and value under locks.
- Resellable goods create a customer-return stock movement using the original issue movement's weighted unit cost. Inventory value is restored and cost of goods sold is credited. Damaged/defective goods are recorded as received but are not added to sellable on-hand stock.
- Posting creates a GST-adjusted customer credit note, reverses revenue and output GST, reduces open AR, and credits customer deposits for any amount exceeding the invoice's remaining receivable. Maker-checker prevents the return creator from posting.
- Current valuation limitation: where a FIFO issue consumed multiple cost layers, a partial return uses the original issue movement's weighted unit cost; exact per-layer consumption restoration is still outstanding and requires layer-allocation provenance.

## Accounting example
For a K100 customer receipt allocated K80 to posted invoices:
- Debit bank K100.
- Credit accounts receivable K80.
- Credit customer deposits and advances K20.

The K20 remains a customer liability until allocated or refunded through a separately controlled workflow.

## Controls
- Customer, invoice, bank account, receipt and allocations are checked against the active company.
- Branch-restricted users cannot post receipts or allocate funds outside their assigned branch.
- Invoice allocations must belong to the receipt customer and target posted invoices.
- Posting transactions lock source records and recheck current outstanding balances.
- Receipt creator and poster must be different users.
- Customer credit policy is checked during sales-order approval and invoice posting; the customer record is company-scoped and credit administration is restricted to owners/admins.
- Receipt journal and bank transaction are created atomically.
- Posted receipts are not edited in place; corrections require controlled refund/reversal workflows.

## Remaining work before Phase 10 acceptance
- POS refund and void workflows, including safe till cash movements and end-of-day settlement reconciliation.
- Picking/packing scans, dispatch manifests, delivery runs and proof of delivery.
- EFTPOS terminal integration and automated settlement matching.
- Customer statement generation (PDF/CSV), email delivery, statement history and automated overdue reminders.
- Price lists, customer-specific pricing, discount permissions and approval thresholds.
- Multi-currency invoices/receipts and foreign-exchange gains/losses.
- Complete GST debit/adjustment-note workflow and statutory G1 filing automation.
- Receipt reversals and customer advance reallocation with full audit trail.
- Full automated feature coverage for credit-limit exposure, payment/credit offsets, cross-company isolation and branch rules.

## Acceptance checks
- Apply all migrations to a clean PostgreSQL database and run the complete test suite.
- Verify partial payments reduce net AR ageing and fully settled invoices disappear.
- Verify allocation cannot exceed receipt amount or current invoice outstanding.
- Verify a customer cannot pay another customer's invoice.
- Verify maker-checker prevents the receipt creator from posting.
- Verify unallocated receipt value credits customer deposits (2500).
- Verify receipt posting creates a bank transaction and balanced journal.
- Verify branch and tenant isolation for receipt creation, posting, invoice allocation and reports.
- Verify supplier payments cannot allocate to another supplier/company/branch, cannot over-settle bills, require an independent poster, create a balanced AP/bank journal and reduce AP ageing only after posting.
- Verify item-level sales returns cannot exceed invoice-line quantities or previously uncredited invoice value, including competing drafts.
- Verify resellable returns restore stock and inventory value at the original issue movement's weighted unit cost, and credit cost of goods sold.
- Verify damaged/defective returns are recorded but do not increase sellable on-hand stock.
- Verify sales-return posting reverses revenue and output GST, creates a posted credit note, reduces AR and credits customer deposits for any overpayment.
- Verify the return creator cannot post their own return and cancellation releases quantity/value reservations.
- Verify a customer on credit hold cannot approve a sales order or post an invoice.
- Verify approved orders plus unpaid posted invoices cannot exceed the customer credit limit, while posted receipts and credit notes release available credit.
- Verify credit limits and holds cannot be changed by a non-owner/admin or across company boundaries.
- Accountant review is required before production use, especially for customer advance/refund accounting, GST and period-close treatment.

This is an implementation candidate, not a production certification. It must pass CI, migration tests, tenant/branch isolation tests, acceptance review and accountant review before production use.
