# ANERP — Advanced Network Enterprise Resource Planning

Multi-company ERP foundation for Papua New Guinea businesses.

**Target:** VMware ESXi 7.0+ | **Guest:** Ubuntu Server 24.04 LTS | **Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17, Redis, Nginx | **Locale:** PGK / Pacific/Port_Moresby.

> This is an actively developed foundation, not a finished production ERP. Payroll/tax calculations, complete financial posting, granular authorization, integrations and production security controls must be completed and tested before live use.

## ESXi host sizing — 128 GB physical RAM
For the current Compose deployment, start with one Ubuntu VM using 8–12 vCPU, 32 GB RAM and a 500 GB SSD-backed virtual disk. The application, PostgreSQL and Redis currently run together in Docker on this VM. Keep ESXi memory headroom and avoid CPU overcommit until real workload data is available.

As usage grows, the host has room to separate the stack into an application VM (24–32 GB), database VM (16–24 GB), and monitoring/backup VM (8 GB), with the remaining memory reserved for ESXi, cache growth and future workloads. This split topology is a planned scale-out, not yet implemented by the current Compose files.

## Quick start
1. Create an Ubuntu Server 24.04 LTS VM in ESXi and install Docker Engine + Compose.
2. Copy this repository to the VM.
3. Copy `.env.example` to `.env`. Set the same strong random secret in `DB_PASSWORD` and `POSTGRES_PASSWORD`.
4. Run `docker compose build`.
5. Generate a key with `docker compose run --rm app php artisan key:generate --show`; paste the returned `base64:...` value into `APP_KEY` in the host `.env` file.
6. Run `docker compose up -d`.
7. Run `docker compose exec app php artisan migrate --force`.
8. Create the initial company and owner account with `docker compose exec app php artisan aner p:install-admin` (actual command: `anerp:install-admin`).
9. Browse to the VM on your trusted LAN and sign in at `/login`.

Do not expose the bootstrap system to the public internet. Add HTTPS, firewall/VPN, MFA, granular roles, tenant-isolation tests, SMTP, monitoring and encrypted off-host backups before production.

## Current implementation
- Session sign-in/sign-out and login throttling; public registration disabled
- Initial company bootstrap and active company membership context
- Tenant-scoped product catalogue create/list
- PostgreSQL schema foundations for stock movements, suppliers, employees and double-entry journal records
- Docker Compose deployment and CI container-build workflow

See docs/ROADMAP.md, docs/PNG_LOCALISATION.md, docs/ESXI_DEPLOYMENT.md, docs/SECURITY_BASELINE.md and docs/PHASE_2_AUTH_TENANCY.md.
