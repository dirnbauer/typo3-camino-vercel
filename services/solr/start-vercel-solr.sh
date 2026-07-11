#!/usr/bin/env bash

set -euo pipefail

export VERCEL_SOLR_PUBLIC_PORT="${PORT:-80}"
export SOLR_PORT_LISTEN="${SOLR_INTERNAL_PORT:-8983}"
export SOLR_HOME="${SOLR_HOME:-/tmp/solr-home}"
export SOLR_LOGS_DIR="${SOLR_LOGS_DIR:-/tmp/solr-logs}"
export SOLR_PID_DIR="${SOLR_PID_DIR:-/tmp/solr-pids}"
export NGINX_CONF="${NGINX_CONF:-/tmp/nginx-solr.conf}"
export TYPO3_SOLR_SEED_DEMO_DOCS="${TYPO3_SOLR_SEED_DEMO_DOCS:-1}"
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
  local docs_file
  base_url="$(normalize_base_url)"
  changed="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  docs_file="/tmp/typo3-solr-demo-docs.json"

  cat > "${docs_file}" <<EOF
[
  {
    "id": "vercel-demo/pages/1/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 1,
    "pid": 0,
    "variantId": "vercel-demo/pages/1/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1"],
    "access": ["c:0"],
    "title": "Camino",
    "navTitle": "Camino",
    "content": "Camino demo site for planning a Camino route. Find route comparison, frequently asked questions, packing list, privacy and imprint pages.",
    "url": "${base_url}/",
    "keywords": ["camino", "demo", "route", "pilgrimage"]
  },
  {
    "id": "vercel-demo/pages/3/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 3,
    "pid": 2,
    "variantId": "vercel-demo/pages/3/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1", "2", "3"],
    "access": ["c:0"],
    "title": "Privacy",
    "navTitle": "Privacy",
    "content": "Privacy information for the Camino demo site, including data protection and GDPR related notes.",
    "url": "${base_url}/privacy",
    "keywords": ["privacy", "gdpr", "data protection"]
  },
  {
    "id": "vercel-demo/pages/4/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 4,
    "pid": 2,
    "variantId": "vercel-demo/pages/4/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1", "2", "4"],
    "access": ["c:0"],
    "title": "Imprint",
    "navTitle": "Imprint",
    "content": "Imprint and legal notice for the Camino demo site.",
    "url": "${base_url}/imprint",
    "keywords": ["imprint", "legal"]
  },
  {
    "id": "vercel-demo/pages/5/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 5,
    "pid": 1,
    "variantId": "vercel-demo/pages/5/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1", "5"],
    "access": ["c:0"],
    "title": "FAQs",
    "navTitle": "FAQs",
    "content": "Frequently asked Camino questions and answers for the demo site.",
    "url": "${base_url}/faqs",
    "keywords": ["faq", "questions", "camino"]
  },
  {
    "id": "vercel-demo/pages/6/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 6,
    "pid": 1,
    "variantId": "vercel-demo/pages/6/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1", "6"],
    "access": ["c:0"],
    "title": "Packing List",
    "navTitle": "Packing List",
    "content": "Camino packing list with practical items for a Camino route, walking stages, backpack planning and travel preparation.",
    "url": "${base_url}/packing-list",
    "keywords": ["packing", "camino", "backpack", "route"]
  },
  {
    "id": "vercel-demo/pages/7/0/0/c:0",
    "site": "camino",
    "typo3Context_stringS": "Production",
    "siteHash": "vercel-demo",
    "domain_stringS": "${base_url}",
    "appKey": "EXT:solr",
    "type": "pages",
    "uid": 7,
    "pid": 1,
    "variantId": "vercel-demo/pages/7/0/0/c:0",
    "typeNum": 0,
    "created": "2026-01-01T00:00:00Z",
    "changed": "${changed}",
    "rootline": ["1", "7"],
    "access": ["c:0"],
    "title": "Camino Route Comparison",
    "navTitle": "Camino Route Comparison",
    "content": "Compare Camino routes, including Camino Frances route planning, distances, difficulty, stages and practical travel decisions.",
    "url": "${base_url}/camino-route-comparison",
    "keywords": ["camino", "route", "comparison", "frances", "stages"]
  }
]
EOF

  if ! curl -fsS \
    -H 'Content-Type: application/json' \
    --data-binary '{"delete":{"query":"siteHash:vercel-demo AND type:pages"}}' \
    "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/update?commit=true" >/dev/null; then
    echo "Could not clear old Camino demo documents" >&2
    return 1
  fi

  if ! curl -fsS \
    -H 'Content-Type: application/json' \
    --data-binary "@${docs_file}" \
    "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/update/json/docs?commit=true" >/dev/null; then
    echo "Could not seed Camino demo documents" >&2
    return 1
  fi

  local count
  count="$(curl -fsS "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/select?q=*:*&fq=siteHash:vercel-demo&rows=0&wt=json" | sed -n 's/.*"numFound":\([0-9][0-9]*\).*/\1/p')"
  if [ "${count:-0}" -ne 6 ]; then
    echo "Expected 6 Camino demo documents after seeding, found ${count:-0}" >&2
    return 1
  fi

  echo "Seeded 6 Camino demo documents into TYPO3 Solr core_en"
}

(
  for attempt in $(seq 1 240); do
    if curl -fsS "http://127.0.0.1:${SOLR_PORT_LISTEN}/solr/core_en/select?q=*:*&rows=0" >/dev/null 2>&1; then
      if seed_demo_documents; then
        touch /tmp/solr-ready
        ready_ms="$(( $(date +%s%3N) - startup_started_ms ))"
        echo "{\"level\":\"info\",\"component\":\"solr\",\"event\":\"ready\",\"duration_ms\":${ready_ms},\"demo_documents\":6}"
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
