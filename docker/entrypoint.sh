#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-80}"

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

mkdir -p /tmp/typo3/var public/fileadmin/_temp_ public/fileadmin/user_upload public/typo3temp config/system

if [ -z "${TYPO3_ENCRYPTION_KEY:-}" ]; then
  export TYPO3_ENCRYPTION_KEY="$(php -r 'echo bin2hex(random_bytes(48));')"
  echo "TYPO3_ENCRYPTION_KEY was not set; generated an ephemeral key for this container. Set a stable key for production." >&2
fi

if [ ! -L var ]; then
  rm -rf var
  ln -s /tmp/typo3/var var
fi

if [ "${TYPO3_DB_DRIVER:-}" = "pdo_sqlite" ] && [ -n "${TYPO3_DB_DBNAME:-}" ] && [ -f /usr/local/share/typo3-seed/camino.sqlite ] && [ ! -f "${TYPO3_DB_DBNAME}" ]; then
  mkdir -p "$(dirname "${TYPO3_DB_DBNAME}")"
  cp /usr/local/share/typo3-seed/camino.sqlite "${TYPO3_DB_DBNAME}"
fi

if [ -n "${TYPO3_SETUP_ADMIN_PASSWORD:-}" ]; then
  php scripts/apply-admin-password.php
fi

chown -R www-data:www-data /tmp/typo3 public/fileadmin public/typo3temp config || true

if [ "${TYPO3_AUTO_SETUP:-0}" = "1" ]; then
  php scripts/bootstrap-typo3.php
  if [ -n "${TYPO3_SETUP_ADMIN_PASSWORD:-}" ]; then
    php scripts/apply-admin-password.php
  fi
  chown -R www-data:www-data /tmp/typo3 public/fileadmin public/typo3temp config || true
fi

exec "$@"
