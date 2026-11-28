# ESXi deployment and appliance export

## Target environment

Target VMware ESXi 7.0 or newer. The current host has 128 GB physical RAM. Initial ANERP VM sizing: 16 vCPU, 48 GB RAM, 1 TB thin-provisioned virtual disk on SSD-backed datastore, and one server-VLAN vNIC. This is a starting allocation; validate CPU readiness, storage latency, actual concurrent-user load and the other VMs on the host before production sizing. Keep the PostgreSQL and Redis services on the private Docker network.

Use Ubuntu Server 24.04 LTS as the guest OS. Install a supported Docker Engine release and Compose v2. Set guest CPU/memory reservations only after measuring host contention. Use a current ESXi-compatible virtual hardware level supported by the actual target host; do not assume an export created on one host will import unchanged to every ESXi 7.x/8.x build.

## Clean install

1. Provision the VM and configure a static address or DHCP reservation, DNS, NTP and the intended server VLAN.
2. Install Ubuntu security updates, Docker Engine and Compose v2.
3. Clone/copy ANERP and run `./deploy/bootstrap.sh`.
4. Confirm all four services are healthy/running with `docker compose ps`; inspect app, Nginx, PostgreSQL and Redis logs.
5. Confirm the Laravel application responds on the trusted LAN and that data survives container recreation.
6. Back up the database, storage volume and `.env`/APP_KEY securely; perform a restore test before production.

The bootstrap script is repeatable: it preserves an existing `.env` and existing non-placeholder database password, generates an APP_KEY only when missing, and applies migrations. Do not run `php artisan key:generate` again on a live installation because rotating the key can invalidate encrypted data and sessions.

## Upgrade and rollback preparation

Use `deploy/upgrade.sh` for reviewed application upgrades. It creates a PostgreSQL custom-format dump, a compressed copy of the persistent storage volume and a restricted-permission `.env` copy before enabling maintenance mode, rebuilding app/web images and applying migrations. Retain these backups off-host. A failed migration does not automatically restore the database: follow the backup/restore runbook and restore into a verified recovery target before reopening production traffic. CI exercises the upgrade path on a disposable clean installation; it is not a production rollback drill.

## OVA export and import verification

The repository supplies `deploy/export-esxi-ova.sh`, which uses VMware govmomi `govc` to export a powered-off, validated VM as OVF files, packages the descriptor and disks into an OVA, validates the tar archive and writes a SHA-256 checksum.

Required environment variables: `GOVC_URL`, `GOVC_USERNAME`, `GOVC_PASSWORD` and `ANERP_ESXI_VM`. Run the script from an authorized machine with network access to ESXi/vCenter and the `govc` binary installed. The account needs permission to inspect and export the selected VM. Use a dedicated least-privilege account and a secure secret manager; do not put passwords in repository files, command-line arguments or CI logs.

After export, import the OVA into a separate non-production inventory. Verify:
- OVF descriptor and disk checksums, guest hardware compatibility and firmware/boot.
- Network port-group mapping, static/DHCP configuration, DNS and NTP.
- Container startup, database migration status, HTTP health and log output.
- Persistent data after VM reboot and container recreation.
- Backup, restore, and recovery of `.env` and APP_KEY.
- Resource utilization under representative test load.

Do not claim an OVA build/import is complete until these steps have been executed and the artifact/checksum plus import test evidence have been recorded. No host credentials or target ISO/image were supplied for this repository work, so a real ESXi export/import cannot be executed from CI alone.

## Production hardening gates

Before live use: HTTPS, firewall/VPN, restricted management access, MFA and role controls, SMTP, monitoring/alerting, encrypted off-host backups, witnessed restore, RPO/RTO validation, penetration testing, statutory/accounting/payroll review and signed business UAT. Do not expose PostgreSQL or Redis directly.
