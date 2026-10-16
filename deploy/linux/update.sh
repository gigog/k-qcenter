#!/usr/bin/env bash
set -euo pipefail

PROJECT_PATH="${KQCENTER_PROJECT_PATH:-/var/www/k-qcenter}"
BACKUP_PATH="${KQCENTER_BACKUP_PATH:-/var/backups/k-qcenter}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

test -d "$PROJECT_PATH/.git"
sudo install -d -m 0750 "$BACKUP_PATH"
sudo mariadb-dump --single-transaction --routines --triggers kqcenter | gzip -9 | sudo tee "$BACKUP_PATH/kqcenter-$STAMP.sql.gz" >/dev/null

git -C "$PROJECT_PATH" pull --ff-only origin main
php -l "$PROJECT_PATH/api/bootstrap.php"
php -l "$PROJECT_PATH/api/index.php"

sudo cp "$PROJECT_PATH/deploy/linux/nginx-k-qcenter.conf" /etc/nginx/sites-available/k-qcenter
sudo php-fpm8.5 -t
sudo nginx -t
sudo systemctl reload php8.5-fpm
sudo systemctl reload nginx

curl --fail --silent --show-error http://127.0.0.1:8089/api/index.php?action=health
echo
echo "K-QCENTER update complete. Backup: $BACKUP_PATH/kqcenter-$STAMP.sql.gz"

