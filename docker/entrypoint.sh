#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-80}"
TYPO3_SERVERLESS_FILESYSTEM="${TYPO3_SERVERLESS_FILESYSTEM:-1}"

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

prepare_runtime_directory() {
  local source_path="$1"
  local target_path="$2"

  mkdir -p "$target_path"

  if [ ! -L "$source_path" ] && [ -d "$source_path" ] && [ -z "$(find "$target_path" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    cp -a "${source_path}/." "$target_path/"
  fi

  if [ ! -L "$source_path" ]; then
    rm -rf "$source_path"
    ln -s "$target_path" "$source_path"
  fi
}

mkdir -p /tmp/typo3/var /tmp/typo3/fileadmin /tmp/typo3/typo3temp config/system

if [ -z "${TYPO3_ENCRYPTION_KEY:-}" ]; then
  export TYPO3_ENCRYPTION_KEY="$(php -r 'echo bin2hex(random_bytes(48));')"
  echo "TYPO3_ENCRYPTION_KEY was not set; generated an ephemeral key for this container. Set a stable key for production." >&2
fi

prepare_runtime_directory var /tmp/typo3/var

if [ "$TYPO3_SERVERLESS_FILESYSTEM" != "0" ]; then
  prepare_runtime_directory public/fileadmin /tmp/typo3/fileadmin
  prepare_runtime_directory public/typo3temp /tmp/typo3/typo3temp
else
  mkdir -p public/fileadmin/_temp_ public/fileadmin/user_upload public/typo3temp
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
