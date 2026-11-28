# ESXi deployment and appliance export

## Target environment

Target VMware ESXi 7.0 or newer. The physical host has 128 GB RAM. Initial ANERP VM sizing is 16 vCPU, 48 GB RAM, a 1 TB thin-provisioned virtual disk on SSD-backed datastore storage and one server-VLAN vNIC. This is a starting point: validate CPU readiness, datastore latency, concurrent users and other guest allocations before production sizing. Keep PostgreSQL and Redis on the private Docker network.

Use Ubuntu Server 24.04 LTS with Docker Engine and Compose v2. Use a virtual hardware level supported by the actual target ESXi build; do not assume every ESXi 7.x/8.x host imports the same hardware profile without adjustment.

## Clean installation

1. Provision the VM, configure static addressing or DHCP reservation, DNS, NTP and server VLAN.
2. Install Ubuntu updates, Docker Engine and Compose v2.
3. Clone/copy ANERP and run `./deploy/bootstrap.sh`.
4. Create the first company and owner with `docker compose exec app php artisan aner p:install-admin` (command name `anerp:install-admin`).
5. Verify `docker compose ps`, application logs and the `/up` health endpoint.
6. Verify persistence after container recreation. Back up PostgreSQL, storage and `.env`/APP_KEY and test a restore before production.

The bootstrap script creates `.env` only when absent, generates a database password when placeholders are present, generates APP_KEY only when missing, starts services and applies migrations. Do not rotate APP_KEY on a live installation unless performing a controlled key-rotation plan.

## Upgrade and rollback preparation

Use `deploy/upgrade.sh` for reviewed releases. It creates a PostgreSQL custom-format dump, compressed persistent-storage backup and restricted-permission `.env` copy before enabling maintenance mode, rebuilding app/web images and applying migrations. Retain backups off-host. A failed migration does not automatically restore the database; use the backup/restore runbook and restore into a verified recovery target before reopening traffic. CI exercises this process on a disposable installation, not a production rollback drill.

## OVA export and import verification

The repository supplies `deploy/export-esxi-ova.sh`, which uses VMware govmomi `govc` to export a powered-off validated VM as OVF files, packages the descriptor and disks into an OVA, validates the archive and writes a SHA-256 checksum.

Required variables: `GOVC_URL`, `GOVC_USERNAME`, `GOVC_PASSWORD`, `ANERP_ESXI_VM`. Run from an authorized host with govc installed and network access to ESXi/vCenter. Use a dedicated least-privilege account and a secure secret manager; do not put credentials in repository files or CI logs.

After export, import into a separate non-production inventory and verify:
- OVF descriptor, disk files, checksum, guest hardware compatibility and boot.
- Network port-group mapping, IP configuration, DNS and NTP.
- Container startup, migrations, HTTP health and application logs.
- Persistence after VM reboot and container recreation.
- Backup/restore of PostgreSQL, storage and `.env`/APP_KEY.
- Resource utilization under representative workload.

CI tests OVA packaging with a mock export only. It does not access the actual ESXi host. Do not claim an OVA build/import is complete until a real target-host export and test import have been recorded.

## Production hardening gates

Before live use: HTTPS, firewall/VPN, restricted management access, MFA and roles, SMTP, monitoring/alerting, encrypted off-host backups, witnessed restore, RPO/RTO validation, penetration testing, statutory/accounting/payroll review and signed business UAT. Never expose PostgreSQL or Redis directly.
