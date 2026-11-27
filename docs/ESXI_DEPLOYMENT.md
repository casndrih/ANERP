# ESXi deployment — 128 GB host

Target ESXi 7.0 and newer. Use a conservative virtual hardware compatibility level 17 as the ESXi 7.0 baseline, then verify the destination host and vCenter combination before importing an OVA.

## Current deployment topology
The repository's current Docker Compose file runs Laravel, PostgreSQL, Redis and Nginx together on one Ubuntu Server 24.04 LTS VM:
- 8–12 vCPU
- 32 GB RAM
- 500 GB SSD-backed virtual disk
- One server-VLAN vNIC; static IP or DHCP reservation

Keep ESXi memory and CPU headroom; do not allocate all physical resources to ANERP.

## Planned scale-out topology
When concurrent users and database load justify it, split into:
- Application VM: 24–32 GB RAM
- Database VM: 16–24 GB RAM, fast SSD datastore and separate backup plan
- Monitoring/backup VM: 8 GB RAM
- Remaining host memory for ESXi, growth and other workloads

This split is a future architecture; it is not what the current Compose deployment configures.

## Deployment
Install Docker Engine and Compose on Ubuntu, copy the repository, configure .env, build the app image, generate APP_KEY using `docker compose run --rm app php artisan key:generate --show` and paste it into the host .env. Then start Compose, run migrations and bootstrap the first owner with `docker compose exec app php artisan anerp:install-admin`.

Add HTTPS, firewall/VPN, SMTP, monitoring and encrypted off-host backups before production. Do not expose PostgreSQL or Redis.

The OVA must be built against the confirmed ESXi build, datastore, port group and guest image; never bake secrets into the appliance.
