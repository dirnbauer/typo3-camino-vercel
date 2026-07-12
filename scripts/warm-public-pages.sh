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

for pass in 1 2; do
  echo "Public edge-cache warm-up pass ${pass}/2"
  for path in "${paths[@]}"; do
    result="$(curl --silent --show-error --location \
      --retry 2 --retry-delay 1 --max-time 30 \
      --output /dev/null \
      --write-out '%{http_code} %{time_starttransfer}' \
      "${base_url}${path}")"
    status="${result%% *}"
    if [[ "${status}" != "200" ]]; then
      echo "Warm-up failed for ${path}: HTTP ${status}" >&2
      exit 1
    fi
    printf '  %-34s %s\n' "${path}" "${result}"
  done
done
