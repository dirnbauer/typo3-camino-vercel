#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-80}"
TYPO3_SERVERLESS_FILESYSTEM="${TYPO3_SERVERLESS_FILESYSTEM:-1}"

export TMPDIR="${TMPDIR:-/tmp/typo3/tmp}"
export TMP="${TMP:-$TMPDIR}"
export TEMP="${TEMP:-$TMPDIR}"
export MAGICK_TEMPORARY_PATH="${MAGICK_TEMPORARY_PATH:-/tmp/typo3/gm}"

if [ -f /etc/apache2/ports.conf ]; then
  sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
  sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf
fi

if [ -f /etc/nginx/nginx.conf ]; then
  sed -ri "s/listen [0-9]+ default_server;/listen ${PORT} default_server;/" /etc/nginx/nginx.conf
fi

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

should_bootstrap_typo3() {
  case "${TYPO3_AUTO_SETUP:-0}" in
    1|true|TRUE|yes|YES|on|ON)
      return 0
      ;;
  esac

  if [ "${TYPO3_BOOTSTRAP_EMPTY_DATABASE:-1}" = "1" ] && [ -n "${TYPO3_SETUP_ADMIN_PASSWORD:-}" ]; then
    if [ -n "${DATABASE_URL:-}" ] || [ -n "${POSTGRES_URL:-}" ] || [ -n "${MYSQL_URL:-}" ]; then
      return 0
    fi
  fi

  return 1
}

should_run_extension_setup() {
  case "${TYPO3_EXTENSION_SETUP_ON_BOOT:-0}" in
    1|true|TRUE|yes|YES|on|ON)
      return 0
      ;;
  esac

  return 1
}

should_apply_admin_password() {
  if [ -z "${TYPO3_SETUP_ADMIN_PASSWORD:-}" ]; then
    return 1
  fi

  case "${TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT:-0}" in
    1|true|TRUE|yes|YES|on|ON)
      return 0
      ;;
  esac

  return 1
}

should_apply_object_storage() {
  case "${TYPO3_OBJECT_STORAGE_ENABLED:-}" in
    1|true|TRUE|yes|YES|on|ON)
      return 0
      ;;
    0|false|FALSE|no|NO|off|OFF)
      return 1
      ;;
  esac

  if [ -n "${TYPO3_S3_BUCKET:-}" ]; then
    return 0
  fi

  if [ -n "${TYPO3_OBJECT_STORAGE_DRIVER:-}" ] || [ -n "${TYPO3_BLOB_ENABLED:-}" ] || [ -n "${BLOB_READ_WRITE_TOKEN:-}" ] || [ -n "${BLOB_STORE_ID:-}" ]; then
    return 0
  fi

  return 1
}

apply_object_storage() {
  if should_apply_object_storage; then
    php scripts/apply-object-storage.php
  fi
}

should_apply_solr_config() {
  case "${TYPO3_SOLR_ENABLED:-}" in
    1|true|TRUE|yes|YES|on|ON)
      return 0
      ;;
    0|false|FALSE|no|NO|off|OFF)
      return 1
      ;;
  esac

  if [ -n "${TYPO3_SOLR_URL:-}" ] || [ -n "${SOLR_URL:-}" ] || [ -n "${TYPO3_SOLR_HOST:-}" ] || [ -n "${SOLR_HOST:-}" ]; then
    return 0
  fi

  return 1
}

apply_solr_config() {
  if should_apply_solr_config; then
    php scripts/apply-solr-config.php
  fi
}

apply_database_defaults() {
  if [ -n "${DATABASE_URL:-}" ] || [ -n "${POSTGRES_URL:-}" ] || [ -n "${MYSQL_URL:-}" ]; then
    return
  fi

  if [ -z "${TYPO3_DB_DRIVER:-}" ]; then
    if [ "${VERCEL:-}" = "1" ] || [ -n "${VERCEL_URL:-}" ]; then
      export TYPO3_DB_DRIVER="pdo_sqlite"
    else
      return
    fi
  fi

  case "${TYPO3_DB_DRIVER}" in
    sqlite|pdo_sqlite)
      export TYPO3_DB_DRIVER="pdo_sqlite"
      if [ -z "${TYPO3_DB_DBNAME:-}" ]; then
        export TYPO3_DB_DBNAME="${TYPO3_DB_PATH:-/tmp/typo3/camino.sqlite}"
      fi
      ;;
  esac
}

run_extension_setup() {
  vendor/bin/typo3 extension:setup --no-interaction
}

fix_runtime_permissions() {
  chown www-data:www-data \
    /tmp/typo3 \
    /tmp/typo3/var \
    /tmp/typo3/var/cache \
    /tmp/typo3/var/lock \
    /tmp/typo3/var/log \
    /tmp/typo3/fileadmin \
    /tmp/typo3/typo3temp \
    /tmp/typo3/tmp \
    /tmp/typo3/gm \
    /tmp/typo3/php-sessions \
    config \
    config/system 2>/dev/null || true
  chmod -R a+rwX /tmp/typo3 2>/dev/null || true
  chown -h www-data:www-data var public/fileadmin public/typo3temp 2>/dev/null || true

  if [ "${TYPO3_DB_DRIVER:-}" = "pdo_sqlite" ] && [ -n "${TYPO3_DB_DBNAME:-}" ]; then
    chown www-data:www-data "$(dirname "${TYPO3_DB_DBNAME}")" "${TYPO3_DB_DBNAME}" 2>/dev/null || true
    chmod 0660 "${TYPO3_DB_DBNAME}" 2>/dev/null || true
  fi
}

fix_runtime_permissions_recursive() {
  chown -R www-data:www-data /tmp/typo3 config || true
  chmod -R a+rwX /tmp/typo3 2>/dev/null || true
  chown -h www-data:www-data var public/fileadmin public/typo3temp 2>/dev/null || true
}

mkdir -p \
  /tmp/typo3/var \
  /tmp/typo3/var/cache \
  /tmp/typo3/var/lock \
  /tmp/typo3/var/log \
  /tmp/typo3/fileadmin \
  /tmp/typo3/typo3temp \
  /tmp/typo3/tmp \
  /tmp/typo3/gm \
  /tmp/typo3/php-sessions \
  config/system

if [ -z "${TYPO3_ENCRYPTION_KEY:-}" ]; then
  if [ "${VERCEL:-}" = "1" ] || [ -n "${VERCEL_URL:-}" ]; then
    echo "FATAL: TYPO3_ENCRYPTION_KEY is not set. On Vercel, instances are ephemeral and scale to zero, so a per-instance random key would break cHash validation (enforceValidation is on) and cache/session integrity across concurrent instances. Set a stable 96-character hex key, e.g. 'openssl rand -hex 48'." >&2
    exit 1
  fi
  export TYPO3_ENCRYPTION_KEY="$(php -r 'echo bin2hex(random_bytes(48));')"
  echo "TYPO3_ENCRYPTION_KEY was not set; generated an ephemeral key for this local container. Set a stable key for production." >&2
fi

apply_database_defaults

prepare_runtime_directory var /tmp/typo3/var
mkdir -p /tmp/typo3/var/cache /tmp/typo3/var/lock /tmp/typo3/var/log
chmod -R a+rwX /tmp/typo3 2>/dev/null || true

if [ "$TYPO3_SERVERLESS_FILESYSTEM" != "0" ]; then
  prepare_runtime_directory public/fileadmin /tmp/typo3/fileadmin
  prepare_runtime_directory public/typo3temp /tmp/typo3/typo3temp
else
  mkdir -p public/fileadmin/_temp_ public/fileadmin/user_upload public/typo3temp
fi

if [ "${TYPO3_DB_DRIVER:-}" = "pdo_sqlite" ] && [ -n "${TYPO3_DB_DBNAME:-}" ] && [ -f /usr/local/share/typo3-seed/camino.sqlite ] && [ ! -f "${TYPO3_DB_DBNAME}" ]; then
  mkdir -p "$(dirname "${TYPO3_DB_DBNAME}")"
  cp /usr/local/share/typo3-seed/camino.sqlite "${TYPO3_DB_DBNAME}"
  chown www-data:www-data "${TYPO3_DB_DBNAME}" 2>/dev/null || true
  chmod 0660 "${TYPO3_DB_DBNAME}" 2>/dev/null || true
fi

if should_apply_admin_password; then
  php scripts/apply-admin-password.php
fi

apply_object_storage

fix_runtime_permissions

if should_bootstrap_typo3; then
  php scripts/bootstrap-typo3.php
  if [ -n "${TYPO3_SETUP_ADMIN_PASSWORD:-}" ]; then
    php scripts/apply-admin-password.php
  fi
  apply_object_storage
  fix_runtime_permissions_recursive
fi

apply_solr_config

if should_run_extension_setup; then
  run_extension_setup
  if should_apply_admin_password; then
    php scripts/apply-admin-password.php
  fi
  apply_object_storage
  fix_runtime_permissions_recursive
fi

exec "$@"
