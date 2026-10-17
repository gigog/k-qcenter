#!/usr/bin/env bash
set -euo pipefail

PROJECT_PATH="${KQCENTER_PROJECT_PATH:-/var/www/k-qcenter}"
OUTPUT_PATH="${1:-/tmp/kqcenter-config.json}"

umask 077
mariadb --batch --raw --skip-column-names kqcenter < "$PROJECT_PATH/deploy/linux/export-config.sql" > "$OUTPUT_PATH"
php -r '$data=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); if (($data["format"] ?? "") !== "k-qcenter-config-v1") { throw new RuntimeException("Invalid export format"); }' "$OUTPUT_PATH"

chmod 600 "$OUTPUT_PATH"
echo "K-QCENTER configuration exported to $OUTPUT_PATH"
echo "This file excludes users, credentials, bookings, queue tickets, queue events, slots, and audit logs."
