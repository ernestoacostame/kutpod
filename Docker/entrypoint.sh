#!/usr/bin/env bash
set -euo pipefail

mkdir -p /var/www/html/storage/media /var/www/html/storage/backups
chown -R www-data:www-data /var/www/html/storage

php-fpm -D
exec nginx -g "daemon off;"
