# ANERP — Advanced Network Enterprise Resource Planning

Multi-company ERP foundation for Papua New Guinea businesses.

**Target stack:** Ubuntu Server 24.04 LTS, Docker Engine/Compose v2, Laravel 12, PHP 8.4, PostgreSQL 17, Redis 7 and Nginx. **Business defaults:** PGK, Papua New Guinea, `Pacific/Port_Moresby`.

ANERP is under active implementation. Passing CI and producing an OVA do not certify accounting, payroll, tax compliance, security or production readiness. See [the phase completion audit](docs/PHASE_COMPLETION_AUDIT.md) for release gates.

## ESXi host profile

The intended host has 128 GB physical RAM. A sensible initial ANERP VM allocation is 16 vCPU, 48 GB RAM and a 1 TB thin-provisioned virtual disk on SSD-backed datastore storage, with a single server-VLAN vNIC. This leaves memory for ESXi and other guests. Confirm CPU contention, storage IOPS, actual workload and concurrent users before scaling; vCPU count is a starting allocation, not a processor compatibility guarantee.

## Repeatable installation

1. Create an Ubuntu Server 24.04 LTS VM and install a supported Docker Engine release with Compose v2.
2. Clone or copy this repository to the VM.
3. From the repository root, run:

   ```bash
   chmod +x deploy/bootstrap.sh
   ./deploy/bootstrap.sh
   ```

4. The bootstrap script creates a private `.env` from `.env.example` if absent, generates a strong database password when the example placeholder is present, builds the application image, generates the Laravel `APP_KEY` once, validates Compose, starts the services and applies database migrations.
5. Browse to the VM on the trusted server LAN. Check `docker compose ps` and `docker compose logs --tail=100 app web db redis`.

The script preserves an existing `.env` and non-placeholder database password. Back up `.env` securely: losing `APP_KEY` can make encrypted application data unreadable. Do not commit or email `.env`.

## ESXi OVA export

Build and validate the ANERP guest VM first, then power it off. Install VMware govmomi's `govc` on an authorized build host and set:

```bash
export GOVC_URL='https://your-esxi-or-vcenter/sdk'
export GOVC_USERNAME='export-user'
export GOVC_PASSWORD='use-a-secret-manager-or-secure-shell'
export ANERP_ESXI_VM='ANERP-Production-Template'
# Optional for a lab with a self-signed certificate only:
# export GOVC_INSECURE=1
./deploy/export-esxi-ova.sh ./dist
```

The script exports OVF files, packages them into an OVA, validates the archive and writes a SHA-256 checksum. Keep credentials out of shell history and CI logs. Import the resulting OVA into a separate, non-production ESXi inventory, verify virtual hardware compatibility, network mapping, boot, application health, persistence and backup/restore before distributing it. An OVA has not been built or imported until this procedure has actually been run against the target ESXi host.

## Operations

- PostgreSQL and Redis are internal Compose services; do not publish their ports to untrusted networks.
- Restrict HTTP to the trusted LAN during bootstrap. Configure HTTPS termination, firewall/VPN, MFA, monitoring, SMTP, encrypted off-host backups and a tested restore process before production.
- Preserve and securely back up the application key and database credentials.
- Run migrations through the controlled release process, not by editing the production database manually.

See [ESXi deployment](docs/ESXI_DEPLOYMENT.md), [PNG localisation](docs/PNG_LOCALISATION.md), [security baseline](docs/SECURITY_BASELINE.md), and [roadmap](docs/ROADMAP.md).
