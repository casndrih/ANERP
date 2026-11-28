# ANERP backup and restore runbook

## Purpose and ownership
This runbook is an operational template for the current Docker Compose deployment. Assign a named system owner and backup custodian before production. Store backup encryption keys separately from the backup destination.

## Data to protect
- PostgreSQL database (authoritative business records).
- User-uploaded documents and generated attachments, if enabled.
- Deployment configuration and secret inventory (never store plaintext .env in ordinary backups).
- Release version, container image tags and migration version.
- Operational logs required for incident investigation.

## Suggested starting policy
Agree final retention with the business owner and applicable PNG obligations. A conservative initial schedule is:
- PostgreSQL encrypted full backup nightly.
- PostgreSQL WAL/PITR archiving if configured and operationally tested.
- Uploads and configuration inventory backed up nightly.
- Keep 7 daily, 4 weekly and 12 monthly encrypted restore points, subject to policy.
- Copy backups to a separate off-host location; same-datastore ESXi snapshots are not independent backups.
- Alert on missed backups, failed encryption, insufficient space and stale restore points.

## PostgreSQL backup example
Run from a trusted administration host with credentials supplied through a protected environment or .pgpass; do not put passwords in shell history.

```bash
umask 077
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
docker compose exec -T db sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "anerp-${STAMP}.dump"
test -s "anerp-${STAMP}.dump"
```

Encrypt before transfer using an approved tool and a recipient/public key held outside the server:

```bash
age -r "$BACKUP_AGE_RECIPIENT" -o "anerp-${STAMP}.dump.age" "anerp-${STAMP}.dump"
shred -u "anerp-${STAMP}.dump"
```

The corresponding age identity/private key must be protected offline and available to authorized recovery personnel. Do not store it beside backups.

## Restore drill (isolated environment only)
1. Select a backup and verify its checksum/signature and provenance.
2. Decrypt it into a restricted temporary directory.
3. Restore into a new, isolated PostgreSQL instance, never over the live database.
4. Use pg_restore --list to inspect archive contents, then restore with an appropriately privileged role.
5. Start the matching ANERP release against the restored database.
6. Run Laravel migrations only if the release procedure requires it; do not blindly migrate an evidence copy.
7. Verify login, company membership, representative inventory, payroll and finance records, report totals and uploaded files.
8. Record elapsed recovery time, missing data window, errors and approver sign-off.
9. Destroy temporary plaintext copies securely after the drill.

Example restore command for a disposable database:

```bash
pg_restore --clean --if-exists --no-owner --role="$RESTORE_DB_USER" \
  --dbname="$RESTORE_DATABASE_URL" "anerp-restore.dump"
```

## Recovery objectives
The business owner must approve realistic Recovery Point Objective (RPO) and Recovery Time Objective (RTO). Until backup frequency, WAL archiving and restore drills are measured, ANERP has no guaranteed RPO/RTO.

## Evidence log
For each run record: timestamp, operator, source release, backup identifier, encrypted off-host destination, checksum, encryption verification, restore target, elapsed time, validation results, exceptions and approver.

## Safety
- Never test restore on the production database.
- Never email database dumps or upload plaintext dumps to public storage.
- Never commit .env, passwords, private keys, database URLs or customer data.
- ESXi snapshots are not a substitute for application-consistent database backups.