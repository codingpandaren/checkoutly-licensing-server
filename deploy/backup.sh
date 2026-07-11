#!/bin/sh
# Nightly MariaDB dump. Install via cron on the VPS:
#   15 3 * * * /opt/checkoutly-licensing/deploy/backup.sh >> /var/log/checkoutly-backup.log 2>&1
set -eu

DIR=/opt/checkoutly-licensing
cd "$DIR"

# DB_NAME / DB_ROOT_PASSWORD come from the compose .env.
. "$DIR/.env"

mkdir -p backups
TS=$(date +%F_%H%M)
OUT="backups/licensing_${TS}.sql"

docker compose -f docker-compose.prod.yml exec -T db \
	mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" --single-transaction --databases "$DB_NAME" > "$OUT"

gzip -f "$OUT"

# Keep 14 days of dumps.
find backups -name 'licensing_*.sql.gz' -mtime +14 -delete

echo "$(date -Is) backup ok: ${OUT}.gz"
