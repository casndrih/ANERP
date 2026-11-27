# ANERP — Advanced Network Enterprise Resource Planning

Multi-company ERP foundation for Papua New Guinea businesses.

**Target:** VMware ESXi 7.0+ | **Guest:** Ubuntu Server 24.04 LTS | **Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17, Redis, Nginx | **Locale:** PGK / Pacific/Port_Moresby.

> This is an initial engineering foundation, not a finished production ERP. Payroll/tax calculations, financial posting, tenant authorization, integrations and security hardening must be completed and tested before live use.

## Small ESXi host
Start with one VM: 8 vCPU, 16 GB RAM (24 GB preferred), 250 GB SSD-backed disk and one server-VLAN vNIC. Keep app/PHP around 3–4 GB, PostgreSQL 4–6 GB and Redis around 512 MB, leaving headroom for Ubuntu and Nginx.

## Quick start
1. Create an Ubuntu Server 24.04 LTS VM in ESXi and install Docker Engine + Compose.
2. Copy this repository to the VM.
3. Copy .env.example to .env and set the same strong database password in DB_PASSWORD and POSTGRES_PASSWORD. Generate an APP_KEY with `docker compose exec app php artisan key:generate --show`, paste the returned base64 key into the host .env file, then restart the app container.
4. Run docker compose build && docker compose up -d.
5. Run docker compose exec app php artisan key:generate.
6. Run docker compose exec app php artisan migrate --force.
7. Browse to the VM on your trusted LAN.

Do not expose the bootstrap system to the public internet. Add HTTPS, firewall/VPN, MFA, roles, tenant-isolation tests, SMTP, monitoring and encrypted off-host backups first.

See docs/ROADMAP.md, docs/PNG_LOCALISATION.md, docs/ESXI_DEPLOYMENT.md and docs/SECURITY_BASELINE.md.
