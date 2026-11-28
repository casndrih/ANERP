# ANERP — Advanced Network Enterprise Resource Planning

Multi-company ERP for Papua New Guinea businesses, under active phased implementation.

**Stack:** Ubuntu Server 24.04 LTS, Docker Engine/Compose v2, Laravel 12, PHP 8.4, PostgreSQL 17, Redis 7 and Nginx. **Defaults:** PGK, Papua New Guinea, `Pacific/Port_Moresby`.

ANERP is not production-certified. Use [the phase completion audit](docs/PHASE_COMPLETION_AUDIT.md) to track code, test, statutory and operational acceptance gates.

## ESXi host profile

The target host has 128 GB physical RAM. Start with one ANERP VM at 16 vCPU, 48 GB RAM and a 1 TB thin-provisioned virtual disk on SSD-backed datastore storage, with one server-VLAN vNIC. Leave memory and CPU headroom for ESXi and other guests. Validate actual CPU readiness, storage latency, concurrent-user load and datastore capacity before scaling. Separate app/database/monitoring VMs remain a future scale-out, not the current Compose topology.

## Clean installation

1. Create an Ubuntu Server 24.04 LTS VM and install Docker Engine plus Compose v2.
2. Copy/clone this repository to the VM.
3. Run:

   ```bash
   chmod +x deploy/bootstrap.sh
   ./deploy/bootstrap.sh
   ```

4. The bootstrap script creates a private `.env` if absent, generates a strong PostgreSQL password if the example placeholder is present, builds the app image, generates the Laravel `APP_KEY` only if missing, starts services and applies migrations.
5. Create the initial company and owner account using `docker compose exec app php artisan aner p:install-admin` (command name: `anerp:install-admin`). Supply the company, administrator and strong password when prompted.
6. Browse to the VM on the trusted LAN and sign in at `/login`.

The bootstrap script preserves an existing `.env` and non-placeholder database password. Back up `.env` and the APP_KEY securely; losing the key can make encrypted application data unreadable. Never commit or email `.env`.

## Upgrade and backups

Run `chmod +x deploy/upgrade.sh && ./deploy/upgrade.sh` for a reviewed application release. It backs up PostgreSQL, the persistent storage volume and `.env` before enabling maintenance mode, rebuilding app/web images and applying migrations. Set `ANERP_BACKUP_DIR` to a secure backup location. Copy backups off-host and test restores; this script does not automatically roll back a failed migration.

## ESXi OVA export

After validating and powering off the guest VM, install VMware govmomi's `govc` on an authorized build host and set `GOVC_URL`, `GOVC_USERNAME`, `GOVC_PASSWORD` and `ANERP_ESXI_VM`. Then run `bash deploy/export-esxi-ova.sh ./dist`. The script exports OVF files, packages the OVA and writes a SHA-256 checksum. Import into a separate non-production ESXi inventory and verify boot, hardware compatibility, networking, persistence and backup/restore. No actual OVA is certified until that host import test is completed.

## Current implementation scope

- Company tenancy, owner bootstrap, login, membership administration, privileged-user MFA and permission controls.
- Products, suppliers, warehouses, stock movements, procurement and receiving.
- HR/payroll workflows and PNG statutory calculation foundations.
- General ledger, supplier/customer settlements, GST management summaries and bank reconciliation.
- Inventory valuation, stock counts, procurement invoice matching, landed costs and supplier returns.
- Sales orders, POS till sessions, customer receipts, credit notes, customer credit limits and item-level customer returns.
- Reporting, security checks and backup/restore documentation.

See [roadmap](docs/ROADMAP.md), [ESXi deployment](docs/ESXI_DEPLOYMENT.md), [PNG localisation](docs/PNG_LOCALISATION.md), [PNG payroll statutory rules](docs/PNG_PAYROLL_STATUTORY_RULES.md), [security baseline](docs/SECURITY_BASELINE.md) and [phase audit](docs/PHASE_COMPLETION_AUDIT.md).
