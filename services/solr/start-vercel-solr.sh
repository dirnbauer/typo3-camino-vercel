#!/usr/bin/env bash

set -euo pipefail

export VERCEL_SOLR_PUBLIC_PORT="${PORT:-80}"
export SOLR_PORT_LISTEN="${SOLR_INTERNAL_PORT:-8983}"
export SOLR_HOME="${SOLR_HOME:-/tmp/solr-home}"
export SOLR_LOGS_DIR="${SOLR_LOGS_DIR:-/tmp/solr-logs}"
export SOLR_PID_DIR="${SOLR_PID_DIR:-/tmp/solr-pids}"
export NGINX_CONF="${NGINX_CONF:-/tmp/nginx-solr.conf}"
export TYPO3_SOLR_SEED_DEMO_DOCS="${TYPO3_SOLR_SEED_DEMO_DOCS:-1}"
export TYPO3_SOLR_DEMO_CORES="${TYPO3_SOLR_DEMO_CORES:-core_en core_de core_es core_zh core_hu}"
startup_started_ms="$(date +%s%3N)"

mkdir -p "${SOLR_HOME}" "${SOLR_LOGS_DIR}" "${SOLR_PID_DIR}"
rm -f /tmp/solr-ready

if [ ! -f "${SOLR_HOME}/solr.xml" ]; then
  cp -a /var/solr/data/. "${SOLR_HOME}/"
fi

chown -R solr:solr "${SOLR_HOME}" "${SOLR_LOGS_DIR}" "${SOLR_PID_DIR}"

echo "{\"level\":\"info\",\"component\":\"solr\",\"event\":\"startup\",\"internal_port\":${SOLR_PORT_LISTEN},\"public_port\":${VERCEL_SOLR_PUBLIC_PORT}}"

cat > "${NGINX_CONF}" <<EOF
pid /tmp/nginx-solr.pid;
error_log /dev/stderr warn;

events {
  worker_connections 256;
}

http {
  access_log off;
  server_tokens off;
  client_body_temp_path /tmp/nginx-client-body;
  proxy_temp_path /tmp/nginx-proxy;
  fastcgi_temp_path /tmp/nginx-fastcgi;
  uwsgi_temp_path /tmp/nginx-uwsgi;
  scgi_temp_path /tmp/nginx-scgi;
  proxy_connect_timeout 2s;
  proxy_send_timeout 60s;
  proxy_read_timeout 60s;

  server {
    listen ${VERCEL_SOLR_PUBLIC_PORT};

    location = /__health/live {
      default_type application/json;
      return 200 '{"status":"live"}';
    }

    location = /__health/ready {
      if (!-f /tmp/solr-ready) {
        return 503 '{"status":"starting"}';
      }
      proxy_pass http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/admin/ping;
      proxy_set_header Host \$host;
    }

    location / {
      if (!-f /tmp/solr-ready) {
        return 503 '{"status":"starting"}';
      }
      proxy_pass http://127.0.0.1:${SOLR_PORT_LISTEN};
      proxy_set_header Host \$host;
      proxy_set_header X-Forwarded-Proto \$scheme;
      proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    }
  }
}
EOF

env PATH="/opt/java/openjdk/bin:/opt/solr/bin:/opt/solr/docker/scripts:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" \
  /opt/solr/docker/scripts/solr-fg --user-managed --force &

solr_pid="$!"

shutdown() {
  kill "${solr_pid}" >/dev/null 2>&1 || true
  wait "${solr_pid}" >/dev/null 2>&1 || true
}
trap shutdown INT TERM

normalize_base_url() {
  local base_url="${TYPO3_SOLR_DEMO_PUBLIC_BASE_URL:-${VERCEL_PROJECT_PRODUCTION_URL:-${VERCEL_URL:-https://typo3-camino-vercel.vercel.app}}}"
  case "${base_url}" in
    http://*|https://*) printf '%s' "${base_url%/}" ;;
    *) printf 'https://%s' "${base_url%/}" ;;
  esac
}

seed_demo_documents() {
  if [ "${TYPO3_SOLR_SEED_DEMO_DOCS}" != "1" ]; then
    return 0
  fi

  local base_url
  local changed
  local core
  local count
  local docs_file
  local source_file
  base_url="$(normalize_base_url)"
  changed="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

  for core in ${TYPO3_SOLR_DEMO_CORES}; do
    source_file="/opt/typo3-solr-demo/${core}.json"
    docs_file="/tmp/typo3-solr-demo-${core}.json"
    if [ ! -f "${source_file}" ]; then
      echo "Missing Camino demo document catalog for ${core}" >&2
      return 1
    fi

    sed -e "s|__BASE_URL__|${base_url}|g" -e "s|__CHANGED__|${changed}|g" "${source_file}" > "${docs_file}"

    if ! curl -fsS \
      -H 'Content-Type: application/json' \
      --data-binary '{"delete":{"query":"siteHash:vercel-demo AND type:pages"}}' \
      "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/${core}/update?commit=true" >/dev/null; then
      echo "Could not clear old Camino demo documents from ${core}" >&2
      return 1
    fi

    if ! curl -fsS \
      -H 'Content-Type: application/json' \
      --data-binary "@${docs_file}" \
      "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/${core}/update/json/docs?commit=true" >/dev/null; then
      echo "Could not seed Camino demo documents into ${core}" >&2
      return 1
    fi

    count="$(curl -fsS "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/${core}/select?q=*:*&fq=siteHash:vercel-demo&rows=0&wt=json" | sed -n 's/.*"numFound":\([0-9][0-9]*\).*/\1/p')"
    if [ "${count:-0}" -ne 6 ]; then
      echo "Expected 6 Camino demo documents in ${core}, found ${count:-0}" >&2
      return 1
    fi

    echo "Seeded 6 Camino demo documents into TYPO3 Solr ${core}"
  done
}

(
  for _ in $(seq 1 240); do
    if curl -fsS "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/select?q=*:*&rows=0" >/dev/null 2>&1; then
      if seed_demo_documents; then
        touch /tmp/solr-ready
        ready_ms="$(( $(date +%s%3N) - startup_started_ms ))"
        echo "{\"level\":\"info\",\"component\":\"solr\",\"event\":\"ready\",\"duration_ms\":${ready_ms},\"demo_documents\":30,\"demo_cores\":5}"
        exit 0
      fi

      echo "TYPO3 Solr is running but demo seeding is not ready; retrying" >&2
    fi

    if ! kill -0 "${solr_pid}" >/dev/null 2>&1; then
      echo "TYPO3 Solr exited before it became ready" >&2
      exit 1
    fi

    sleep 0.25
  done

  echo "TYPO3 Solr did not become ready within 60s" >&2
) &

echo "Forwarding Vercel port ${VERCEL_SOLR_PUBLIC_PORT} to Solr port ${SOLR_PORT_LISTEN}"
exec nginx -c "${NGINX_CONF}" -g 'daemon off;'
