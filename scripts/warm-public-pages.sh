#!/usr/bin/env bash
set -euo pipefail

base_url="${TYPO3_PUBLIC_BASE_URL:-https://typo3-camino-vercel.vercel.app}"
base_url="${base_url%/}"

paths=(
  /
  /camino-route-comparison
  /packing-list
  /faqs
  /privacy
  /imprint
  /search
  /visual-editor
  /de/
  /de/camino-routenvergleich
  /es/
  /zh/
  /hu/
)

challenged=0

for pass in 1 2; do
  echo "Public edge-cache warm-up pass ${pass}/2"
  for path in "${paths[@]}"; do
    headers="$(mktemp)"
    result="$(curl --silent --show-error --location \
      --retry 2 --retry-delay 1 --max-time 30 \
      --dump-header "${headers}" \
      --output /dev/null \
      --write-out '%{http_code} %{time_starttransfer}' \
      "${base_url}${path}")"
    status="${result%% *}"
    if [[ "${status}" != "200" ]]; then
      # Vercel bot protection may challenge this non-browser client. The
      # deployment is still valid; only cache warming is impossible.
      if grep -qi '^x-vercel-mitigated: challenge' "${headers}"; then
        printf '  %-34s challenged by Vercel firewall; skipping warm-up\n' "${path}"
        challenged=1
        rm -f "${headers}"
        break 2
      fi
      rm -f "${headers}"
      echo "Warm-up failed for ${path}: HTTP ${status}" >&2
      exit 1
    fi
    rm -f "${headers}"
    printf '  %-34s %s\n' "${path}" "${result}"
  done
done

if [[ "${challenged}" == "1" ]]; then
  echo "Warm-up skipped: Vercel bot protection challenges this client. Pages warm on first real visits instead." >&2
fi
