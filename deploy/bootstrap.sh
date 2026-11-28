#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v docker >/dev/null 2>&1 || { echo "Docker Engine is required." >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required." >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "OpenSSL is required." >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "Python 3 is required." >&2; exit 1; }

if [[ ! -f .env ]]; then
  cp .env.example .env
  chmod 600 .env
  echo "Created .env from .env.example."
fi

DB_SECRET="$(openssl rand -hex 32)"
export DB_SECRET
python3 - <<'PY'
import os
from pathlib import Path

path = Path(".env")
lines = path.read_text().splitlines()
values = {
    "DB_PASSWORD": os.environ["DB_SECRET"],
    "POSTGRES_PASSWORD": os.environ["DB_SECRET"],
}
for key, value in values.items():
    prefix = key + "="
    found = False
    for index, line in enumerate(lines):
        if line.startswith(prefix):
            if line.split("=", 1)[1].startswith("REPLACE_WITH_"):
                lines[index] = prefix + value
            found = True
            break
    if not found:
        lines.append(prefix + value)
path.write_text("\n".join(lines) + "\n")
path.chmod(0o600)
PY

if grep -Eq '^APP_KEY=$' .env; then
  echo "Building application image to generate the Laravel application key..."
  docker compose build app
  APP_KEY="$(docker compose run --rm --no-deps app php artisan key:generate --show | tail -n 1)"
  if [[ ! "$APP_KEY" =~ ^base64: ]]; then
    echo "Laravel did not return a valid APP_KEY; refusing to continue." >&2
    exit 1
  fi
  export APP_KEY
  python3 - <<'PY'
import os
from pathlib import Path

path = Path(".env")
lines = path.read_text().splitlines()
key = "APP_KEY="
for index, line in enumerate(lines):
    if line.startswith(key):
        lines[index] = key + os.environ["APP_KEY"]
        break
else:
    lines.append(key + os.environ["APP_KEY"])
path.write_text("\n".join(lines) + "\n")
path.chmod(0o600)
PY
fi

docker compose config --quiet
docker compose up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan storage:link || true

echo "ANERP containers are running. Confirm the VM firewall, HTTPS/VPN, SMTP, monitoring and off-host backups before production use."
