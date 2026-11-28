#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

[[ -f .env ]] || { echo ".env is required; run deploy/bootstrap.sh first." >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Docker Engine is required." >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required." >&2; exit 1; }

BACKUP_DIR="${ANERP_BACKUP_DIR:-$ROOT_DIR/backups}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DB_BACKUP="$BACKUP_DIR/anerp-db-$STAMP.dump"
STORAGE_BACKUP="$BACKUP_DIR/anerp-storage-$STAMP.tar.gz"
ENV_BACKUP="$BACKUP_DIR/anerp-env-$STAMP"
cp .env "$ENV_BACKUP"
chmod 600 "$ENV_BACKUP"

echo "Creating PostgreSQL backup..."
docker compose exec -T db sh -lc 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$DB_BACKUP"
test -s "$DB_BACKUP"

echo "Creating persistent storage backup..."
docker compose exec -T app tar -czf - -C /var/www/html/storage . > "$STORAGE_BACKUP"
test -s "$STORAGE_BACKUP"

restore_app_mode() {
  docker compose exec -T app php artisan up >/dev/null 2>&1 || true
}
trap restore_app_mode EXIT

echo "Enabling Laravel maintenance mode..."
docker compose exec -T app php artisan down --retry=60

echo "Building and replacing application and web images..."
docker compose build app web
docker compose up -d --no-deps --wait app web

echo "Applying database migrations..."
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan up
trap - EXIT

docker compose ps
echo "Upgrade completed. Backups: $DB_BACKUP, $STORAGE_BACKUP, $ENV_BACKUP"
echo "Retain backups off-host and test restore before production releases."
