# Phase 10 — Customer Receipts and Receivables

## Implemented
- Customer receipts are tenant- and branch-scoped and must use an active bank account belonging to the receipt branch.
- Receipts are created as drafts; the creator cannot post their own receipt.
- A receipt can be allocated across multiple posted invoices for the same customer.
- Partial payments are supported. Posted allocations reduce the invoice's outstanding balance.
- Allocation totals cannot exceed the receipt amount, and a posting-time lock/recheck prevents an invoice from being overpaid by concurrent or later receipts.
- Funds not allocated to invoices are credited to customer deposits and advances (GL account 2500); this supports prepayments without incorrectly reducing AR.
- Posting creates a balanced journal: debit the selected bank GL account, credit accounts receivable for allocations, and credit customer deposits for the unallocated balance.
- Posting also creates an incoming bank transaction linked to the journal so the receipt is available to bank reconciliation.
- Receivables ageing reports now show net outstanding invoice balances after posted receipts, rather than original gross invoice values.
- Finance workspace links to dedicated customer receipt and supplier payment registers.
- Supplier payments support drafts, multi-bill allocations, partial bill settlements, maker-checker posting, balanced AP/bank journals, outgoing bank transactions and draft cancellation.
- Customer credit notes support invoice-linked drafts, outstanding-balance controls, independent posting, balanced revenue/AR reversals, cancellation and AR ageing integration. Draft receipts and draft credit notes reserve the same invoice balance so they cannot collectively exceed the invoice.
- Customer deposit refunds support receipt-linked unallocated-balance checks, maker-checker posting, balanced customer-deposit/bank journals, outgoing bank transactions and draft cancellation.
- Sales orders support multi-product draft capture, GST treatment by order, stock availability checks, approved-order stock reservations, independent approval and fulfilment. Fulfilment issues inventory through the valuation ledger, posts cost of sales, and creates a draft customer invoice with product-level invoice lines for independent finance posting.
- POS till sessions support cashier-scoped opening floats, branch/warehouse cash drawers, posted-invoice cash settlement, automatic change calculation, linked customer receipts and bank transactions, manager close/count and expected-versus-counted cash variance. POS cash receipts are an immediate till transaction exception to normal receipt maker-checker; invoice posting remains separately controlled.
- Supplier credit notes support posted PGK general expense bills. Supplier inventory credit notes support matched goods bills, return-quantity and on-hand checks, independent posting, FIFO/weighted-average stock issue, GST input-credit reversal, AP reduction and purchase-price variance accounting.
- Domestic PNG GST is posted on general supplier bills, customer invoices and eligible PO-matched goods/landed-cost invoices with company registration settings, supplier registration/tax-invoice evidence for input credits, GST input/output control accounts and a G1-style management summary. The summary reports sales bases net of output GST.
- Import assessments record the actual Customs-assessed duty, excise, customs value and freight/insurance, plus HS code, import declaration, assessment reference and tariff source/verification date. Import GST is calculated on the recorded statutory base; eligible input GST is posted separately through the existing PO landed-cost workflow, while non-claimable import GST is capitalised into landed cost. No tariff rate is guessed.
- Accounts payable ageing nets posted supplier payment allocations and posted supplier stock returns; draft payments and returns do not reduce reported AP.

## Accounting example
For a K100 customer receipt allocated K80 to posted invoices:
- Debit bank K100.
- Credit accounts receivable K80.
- Credit customer deposits and advances K20.

The K20 remains a customer liability until allocated or refunded through a separately controlled workflow.

## Controls
- Customer, invoice, bank account, receipt and allocations are checked against the active company.
- Branch-restricted users cannot post receipts or allocate funds outside their assigned branch.
- Invoice allocations must belong to the receipt customer and must target posted invoices.
- The posting transaction locks the receipt, bank account and invoices, then rechecks current outstanding balances.
- Receipt creator and poster must be different users.
- Posted receipts are immutable through this workflow; corrections require a future reversal/refund process.
- Receipt journal and bank transaction are created atomically.

## Not included in this increment
- Picking/packing scans, dispatch manifests, proof of delivery, EFTPOS terminal integrations, POS refunds and automated end-of-day settlement remain outstanding.
- General manual invoice entry remains single-line; sales-order-generated invoices preserve product-level lines. Price lists, discount controls, customer credit limits and GST-bearing customer/supplier adjustment notes remain outstanding.
- Customer statement PDF/email delivery and automated reminders.
- Customer advance allocation changes, refunds and receipt reversals.
- Multi-currency receipts and foreign exchange gains/losses.
- Supplier payment drafts, multi-bill allocations, maker-checker posting, AP/bank journal posting and outgoing bank transaction capture are implemented; posted allocations reduce AP ageing. Supplier advances and payment reversals remain out of scope.

## Acceptance checks
- Apply all migrations to a clean PostgreSQL database.
- Verify partial payments reduce net AR ageing and fully settled invoices disappear from outstanding receivables.
- Verify allocation cannot exceed receipt amount or current invoice outstanding.
- Verify a customer cannot pay another customer's invoice.
- Verify maker-checker prevents the receipt creator from posting.
- Verify unallocated receipt value credits customer deposits (2500).
- Verify receipt posting creates a bank transaction and a balanced journal.
- Verify branch and tenant isolation for receipt creation, posting, invoice allocation and reports.
- Verify supplier payments cannot allocate to another supplier/company/branch, cannot over-settle bills, require an independent poster, create a balanced AP/bank journal and reduce AP ageing only after posting.
- Accountant review is required before production use, especially for customer advance/refund accounting and reconciliation.

This is a draft implementation candidate. It is not merged or deployed and must pass CI, migration tests, tenant-isolation tests and accountant review before production use.
