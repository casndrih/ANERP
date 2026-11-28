# ANERP — Advanced Network Enterprise Resource Planning

Multi-company ERP foundation for Papua New Guinea businesses.

**Target:** VMware ESXi 7.0+ | **Guest:** Ubuntu Server 24.04 LTS | **Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17, Redis, Nginx | **Locale:** PGK / Pacific/Port_Moresby.

> This is an actively developed foundation, not a finished production ERP. Payroll special cases, complete accounting workflows, granular authorization, integrations and production security controls must be completed and tested before live use.

- Phase 7 payroll-to-ledger integration: payroll approval generates balanced, branch-attributed accrual journals for gross wages, PAYE, employee/employer superannuation and net wages payable; payment execution remains separate.
- Phase 7 inventory-to-ledger integration: opening stock, receipts, issues, adjustments and inter-branch transfers generate balanced, branch-attributed journals. Phase 8 adds company-configurable FIFO/weighted-average valuation, exact movement cost values and independent stock-count approvals. Phase 9 adds supplier invoice matching and controlled landed-cost allocation. Phase 10 adds customer receipts, invoice allocations, net receivables ageing, invoice credit notes, unallocated-deposit refunds and bank reconciliation integration.
- Phase 7 branch access: company membership branch assignments restrict HR, payroll employee lines/payslips, warehouse stock movements/balances and purchase orders; branch-restricted users are blocked from company-wide HR policy operations; finance records and reports now support branch attribution while company-wide users retain consolidated access.
- Phase 7 company administration: single-use email invitations, tenant-scoped membership and branch assignment, role management, membership suspension/reactivation, invitation revocation, last-owner protection and company-scoped audit history.

## ESXi host sizing — 128 GB physical RAM
For the current Compose deployment, start with one Ubuntu VM using 8–12 vCPU, 32 GB RAM and a 500 GB SSD-backed virtual disk. The application, PostgreSQL and Redis currently run together in Docker on this VM. Keep ESXi memory headroom and avoid CPU overcommit until real workload data is available.

As usage grows, the host has room to separate the stack into an application VM (24–32 GB), database VM (16–24 GB), and monitoring/backup VM (8 GB), with the remaining memory reserved for ESXi, cache growth and future workloads. This split topology is a planned scale-out, not yet implemented by the current Compose files.

## Quick start
1. Create an Ubuntu Server 24.04 LTS VM in ESXi and install Docker Engine + Compose.
2. Copy this repository to the VM.
3. Copy .env.example to .env. Set the same strong random secret in DB_PASSWORD and POSTGRES_PASSWORD.
4. Run docker compose build.
5. Generate a key with docker compose run --rm app php artisan key:generate --show; paste the returned base64 value into APP_KEY in the host .env file.
6. Run docker compose up -d.
7. Run docker compose exec app php artisan migrate --force.
8. Create the initial company and owner account with docker compose exec app php artisan anerp:install-admin.
9. Browse to the VM on your trusted LAN and sign in at /login.

Do not expose the system publicly until HTTPS, firewall/VPN, MFA enrollment and recovery, role assignments, tenant-isolation tests, SMTP, monitoring and encrypted off-host backups have been verified.

- Phase 8 inventory controls: FIFO and moving weighted-average costing, warehouse/product cost balances and FIFO layers; stock counts capture system-vs-physical variance and require independent owner/admin approval before adjustment movements and GL journals post. Historical posted journals are never silently rewritten.
- Phase 9 procurement controls: supplier goods invoices are matched to approved POs and received quantities before GRNI-to-AP posting; landed-cost invoices allocate freight/insurance charges between remaining inventory and COGS, with safeguards for mixed or transferred stock.
- Supplier settlements: draft payments reserve bill balances, support partial multi-bill allocations, require independent posting, create balanced AP/bank journals and outgoing bank transactions, and reduce AP ageing. General expense credit notes and PO-matched inventory stock-return credit notes are supported; stock returns adjust FIFO/weighted-average inventory, eligible GST input credits and AP.
- PNG import assessments: actual Customs-assessed duty, excise and freight/insurance feed import GST calculation; HS code, declaration, assessment reference and official tariff verification are retained. Eligible import GST posts separately through PO landed-cost matching; non-claimable import GST is capitalised.
- Sales and POS: multi-line sales orders reserve stock at approval, fulfil through inventory costing, generate product-level draft invoices and support cash till sessions, posted-invoice receipts, change calculation and cash variance journals.
- PNG GST: domestic GST is calculated and posted for general supplier bills and customer invoices; input credits require company registration, supplier registration details and a tax invoice reference. A G1-style management summary is available but is not filing-ready.

## Current implementation
- Session sign-in/sign-out and login throttling; public registration disabled.
- Initial company bootstrap and active company membership context.
- Tenant-scoped product catalogue create/list.
- Inventory and procurement workflows: warehouses, stock ledger, transfers, suppliers and purchase orders.
- HR and payroll: employee records, leave, attendance, PNG 2026 core PAYE and superannuation calculations, maker-checker approval and payslips.
- Finance foundation: company chart of accounts, balanced journals, supplier bills, customer invoices, customer receipts and invoice allocations, customer credit notes, deposit refunds, supplier payment/credit-note controls, bank transactions and statement reconciliation.
- Automated ledger posting: payroll approval generates branch-level wage and statutory liability accruals; inventory movements generate branch-level inventory/COGS/GRNI/adjustment journals and cross-branch clearing entries. These use recorded movement cost and still require accountant validation.
- Reporting: company-scoped trial balance, P&L, balance sheet, bank cash movement, AP/AR ageing net of posted payments and credit notes, approved payroll summaries, inventory movement reports and CSV exports.
- Phase 7 security increment: central company role/permission matrix, route-level permission checks across core modules, TOTP MFA enforced for privileged company roles, encrypted authenticator secrets and one-time recovery codes, audit event logging for authenticated company writes and report exports, PostgreSQL triggers to block audit-event updates/deletes, unit tests, secret/dependency/container scanning workflow, and backup/restore runbook.

## Documentation
See docs/ROADMAP.md, docs/PNG_LOCALISATION.md, docs/PNG_PAYROLL_STATUTORY_RULES.md, docs/ESXI_DEPLOYMENT.md, docs/SECURITY_BASELINE.md, docs/PHASE_2_AUTH_TENANCY.md, docs/PHASE_3_INVENTORY_PROCUREMENT.md, docs/PHASE_4_HR_PAYROLL.md, docs/PHASE_5_FINANCE_ACCOUNTING.md, docs/PHASE_6_REPORTING_ANALYTICS.md, docs/PHASE_7_PRODUCTION_READINESS.md, docs/PHASE_7_COMPANY_ADMINISTRATION.md, docs/PHASE_7_PAYROLL_LEDGER_INTEGRATION.md, docs/PHASE_7_INVENTORY_LEDGER_INTEGRATION.md, docs/PHASE_8_INVENTORY_VALUATION.md, docs/PHASE_9_PROCUREMENT_CONTROLS.md, docs/PHASE_10_SALES_RECEIVABLES.md and docs/OPERATIONS_BACKUP_RESTORE.md.
