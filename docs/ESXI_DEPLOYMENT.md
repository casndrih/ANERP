# ESXi deployment — 128 GB host

Target ESXi 7.0 and newer. Use a conservative virtual hardware compatibility level 17 as the ESXi 7.0 baseline, then verify the destination host and vCenter combination before importing an OVA.

## Current deployment topology
The repository's current Docker Compose file runs the Laravel application, PostgreSQL, Redis and Nginx together on one Ubuntu Server 24.04 LTS VM:
- 8–12 vCPU
- 32 GB RAM
- 500 GB SSD-backed virtual disk
- One server-VLAN vNIC; static IP or DHCP reservation

Do not allocate the full physical host to this VM. Keep ESXi memory and CPU headroom.

## Planned scale-out topology
When concurrent users and database load justify it, split into:
- Application VM: 24–32 GB RAM
- Database VM: 16–24 GB RAM, fast SSD datastore and separate backup plan
- Monitoring/backup VM: 8 GB RAM
- Remaining host memory for ESXi, growth and other workloads

This split is a future architecture; it is not what the current Compose deployment configures.

## Deployment
Install Docker Engine and Compose on Ubuntu, copy the repository, configure .env, build the app image, generate APP_KEY using artisan key:generate --show and paste it into the host .env, then start Compose and run migrations. Create the initial company/admin with artisan anerp:install-admin (exact command: anerp:install-admin with no space: aner p is just text). Add HTTPS, firewall/VPN, SMTP, monitoring and encrypted off-host backups before production. Do not expose PostgreSQL or Redis.

The OVA must be built against the confirmed ESXi build, datastore, port group and guest image; never bake secrets into the appliance.
