# PNG GST Section 65A — withholding control register

## Legal basis and operating principle

The IRC Section 65A fact sheet explains that the Commissioner General may issue a written notice directing a registered person to pay the GST charged by all or specified suppliers directly to the Commissioner. The notice remains in force until revoked by a further written notice. The person making the remittance must advise the supplier of the amount and payment date, and provide the Commissioner with each supplier's name, registration number, remitted amount and province of supply. The IRC fact sheet also identifies G2, G4, G5 and G6 as withholding remittance forms and lists supplier-listing information including supplier name, TIN, amount incurred, GST amount and nature of expenses.

Official reference: [IRC — GST Section 65A Fact Sheet](https://static.irc.gov.pg/2021/December/5ExWcL-media-GST-Section-65A-Fact-Sheet.pdf).

ANERP therefore does **not** activate withholding by default. A company administrator must record and attach/reference the actual written notice, choose whether it covers all suppliers or named suppliers, record the effective period and select the remittance form stated by the notice or confirmed by the IRC. A written revocation is recorded with its effective date and reference.

## Implemented workflow

1. **Notice register:** capture notice number, issuing authority, notice date, effective start/end, all-supplier or specified-supplier scope, form code, written notice evidence and notes. Activation is restricted to company administrators and overlapping notice periods/supplier scopes are rejected.
2. **Supplier payment withholding:** when a supplier payment is drafted, ANERP checks the notice in force on the payment date, the company GST registration, supplier GST registration and the bill's actual GST. It requires the supplier TIN, GST registration number and province of supply when a taxable bill is covered.
3. **Partial settlements:** the GST amount is apportioned to the gross amount of each bill allocation using the recorded supplier-bill GST amount. Previous draft and posted allocations reserve the GST already withheld, and the calculation is capped at the remaining invoice GST. ANERP does not invent a GST rate or calculate withholding from the gross bill value alone.
4. **Balanced supplier payment:** the gross AP allocation is debited from accounts payable. The net amount is credited to the bank account, and GST withheld is credited to the Section 65A withholding liability (account 2110). The bank transaction records only the net cash actually paid to the supplier.
5. **IRC remittance:** create a remittance batch by notice and payment period, enter the form code, due date and actual payment date/reference, then independently post it. Posting debits the withholding liability and credits the selected PGK bank account, and marks the linked withholding records as remitted.
6. **Supplier advice and listing:** after remittance, record the supplier advice reference. Export a CSV containing supplier name, TIN, GST registration number, supplier invoice/date, payment date, nature of supply, gross amount incurred, GST remitted, province of supply, IRC remittance date/reference and supplier advice evidence.

## Accounting example

For a PGK 100 gross supplier bill containing PGK 9.09 GST, fully settled under an applicable notice:

- Debit accounts payable: K100.00
- Credit bank (cash paid to supplier): K90.91
- Credit Section 65A GST withholding payable (2110): K9.09

When K9.09 is remitted to the IRC:

- Debit Section 65A GST withholding payable (2110): K9.09
- Credit bank: K9.09

The supplier's bill is settled for its gross amount, while the bank reflects only the net supplier payment and the separate remittance.

## Important controls and limitations

- A notice must be backed by a written IRC notice or an official document reference before activation.
- Only one overlapping active notice may apply to a supplier/payment date. Conflicts block supplier-payment processing rather than guessing which notice controls.
- The notice scope and effective dates are evaluated against the supplier and payment date. A later written revocation does not erase earlier valid withholding records.
- Withholding is based on the supplier bill's recorded GST amount and gross payment allocation. The calculation is an accounting allocation of the actual tax charged; it is not a substitute for checking the notice's exact wording and tax treatment.
- The remittance due date is entered from the written notice/current IRC instructions. ANERP does not assume a generic statutory due date.
- Supplier advice is a manually recorded confirmation/reference. ANERP does not email suppliers or submit G1/G2/G4/G5/G6 forms to the IRC.
- The CSV is a supplier/remittance listing export, not an official IRC return. Tax adviser review, reconciliation to the filed form and confirmation of the correct form/due date are required before production use.
- Section 65A does not replace ordinary GST input-credit eligibility, supplier tax-invoice evidence, G1 reporting or the separate GST return process.
