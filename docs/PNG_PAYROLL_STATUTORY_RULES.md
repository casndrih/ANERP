# PNG statutory payroll rules implemented in ANERP

**Rule set:** PNG-ITA-2025-2026.1  
**Effective date:** 1 January 2026  
**Currency:** PGK

## Salary and wages tax (PAYE)

The Income Tax Act 2025, Schedule 1 Part I clauses 3 and 4, specifies fortnightly marginal bands:

| Fortnightly employment income band | Resident | Non-resident |
|---|---:|---:|
| First K770 | 0% | 22% |
| K770–K1,270 | 30% | 30% |
| K1,270–K2,693 | 35% | 35% |
| K2,693–K9,615 | 40% | 40% |
| Above K9,615 | 42% | 42% |

Section 13 imposes salary and wages tax for each fortnight on gross PNG-source employment income, other than exempt income. Schedule 6 clause 2 provides:
- With an employment declaration: apply the relevant progressive fortnightly rates and subtract the allowed annual dependant tax credit divided by 26.
- Without an employment declaration: withhold at the maximum applicable rate (42%) on the fortnightly amount.

Section 56 provides the dependant tax credit. The annual credit depends on eligible dependants and assessed tax, with per-dependant limits and an overall cap. ANERP stores the **approved annual credit amount** supplied by the employer/payroll administrator; it does not infer eligibility from a dependant count. The stored amount is capped at K1,050 and must be supported by the employee's declaration and IRC records.

Schedule 6 clause 5 describes payments for periods longer than a fortnight by converting the payment into full fortnight or part-fortnight amounts using daily earnings. ANERP segments periods longer than 14 days and normalizes weekly periods to a fortnightly equivalent. The weekly proration method should be checked against the latest IRC payroll circular before production use.

## Superannuation

The Bank of Papua New Guinea public notice states:
- Employers with 15 or more employees are within the compulsory regime.
- For employees continuously employed for three months or more, deduct 6% of base salary from the employee and contribute 8.4% of base salary from the employer's own funds.
- Contributions are due to an Authorised Superannuation Fund within 14 days after the end of each calendar month.
- Employers with fewer than 15 employees may elect to participate voluntarily.

ANERP calculates the 6% and 8.4% contributions on base pay only. Overtime, bonuses and commissions are not included in this base-pay calculation. The employee's 6% contribution is deducted from net pay after PAYE; the employer's 8.4% is an additional employer cost.

The system uses the company-wide active headcount across branches. Below 15 employees, the employee's voluntary opt-in flag must be set. HR can mark a documented exemption; exemptions and special industries must be reviewed by the employer. The application does not automatically determine legal exemptions or employee nationality/residency eligibility.

## Implemented controls and exclusions

- Tax residency is stored per employee (resident/non-resident).
- An employment declaration flag controls progressive bands versus the statutory maximum-rate withholding.
- Approved annual dependant credit is stored per employee and capped at K1,050.
- Superannuation opt-in, exemption, fund and member number are stored.
- Each payroll run and payroll line records the rule-set version.
- Payroll is still draft until maker-checker approval.

Not automated in this release: salary packaging/60:40 approvals, non-cash benefits (including motor vehicles/housing), redundancy and long-service-leave concessions, piecework, multiple separate payments within a fortnight, leave fare rules, termination final pay, statutory remittance files, tax returns, superannuation fund upload files and payments.

**Compliance boundary:** This is an implementation of the cited core bands and contribution rates, not a certification of full compliance. Employers must validate individual declarations, tax residency, exempt income, approved dependant credit, statutory exemptions and any later IRC/BPNG amendments with their PNG payroll adviser before processing live payroll.

## Official references

- Income Tax Act 2025, certified 20 October 2025, effective 1 January 2026: https://static.irc.gov.pg/2025/October/0uvVvY-media-certifiedincometaxact2025.pdf
- Bank of Papua New Guinea, Employer and Employee Superannuation Contributions: https://www.bankpng.gov.pg/publications/public-notices/employer-and-employee-superannuation-contributions
- NASFUND, What is Superannuation: https://www.nasfund.com.pg/superannuation/about-superannuation/what-is-superannuation
