# ESXi deployment
Target ESXi 7.0 and newer. Use conservative virtual hardware compatibility level 17 as the ESXi 7.0 baseline, then verify the destination host and vCenter combination before importing an OVA.

Small-host pilot: Ubuntu Server 24.04 LTS, 8 vCPU, 16 GB RAM (24 GB preferred), 250 GB SSD virtual disk, one server VLAN vNIC. Run app, PostgreSQL, Redis and Nginx in one VM to avoid splitting limited RAM.

Install Docker Engine and Compose, copy repository, configure .env, run docker compose build/up, generate Laravel APP_KEY and run migrations. Add HTTPS, firewall/VPN, SMTP, monitoring and encrypted off-host backups before production. Do not expose PostgreSQL or Redis.

The OVA must be built against the confirmed ESXi build, datastore, port group and guest image; never bake secrets into the appliance.
