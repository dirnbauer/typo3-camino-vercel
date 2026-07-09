#!/usr/bin/env bash
set -euo pipefail

mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy
chown -R www-data:www-data /tmp/nginx

php-fpm -F &
php_fpm_pid="$!"

php_fpm_ready=0
for _ in $(seq 1 200); do
  if (exec 3<>/dev/tcp/127.0.0.1/9000) 2>/dev/null; then
    exec 3>&-
    exec 3<&-
    php_fpm_ready=1
    break
  fi
  if ! kill -0 "${php_fpm_pid}" >/dev/null 2>&1; then
    wait "${php_fpm_pid}"
    exit "$?"
  fi
  sleep 0.01
done

if [ "${php_fpm_ready}" != "1" ]; then
  echo "PHP-FPM did not become ready within two seconds." >&2
  kill -TERM "${php_fpm_pid}" >/dev/null 2>&1 || true
  wait "${php_fpm_pid}" >/dev/null 2>&1 || true
  exit 1
fi

nginx -g 'daemon off;' &
nginx_pid="$!"

set +e
wait -n "${php_fpm_pid}" "${nginx_pid}"
status="$?"
set -e

kill -TERM "${php_fpm_pid}" "${nginx_pid}" >/dev/null 2>&1 || true
wait "${php_fpm_pid}" "${nginx_pid}" >/dev/null 2>&1 || true
exit "${status}"
