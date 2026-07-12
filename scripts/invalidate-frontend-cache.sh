#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
scope="${VERCEL_SCOPE:-webconsulting}"

vercel cache invalidate \
  --cwd "${root}" \
  --scope "${scope}" \
  --tag typo3-public \
  --yes

TYPO3_PUBLIC_BASE_URL="${TYPO3_PUBLIC_BASE_URL:-https://typo3-camino-vercel.vercel.app}" \
  "${root}/scripts/warm-public-pages.sh"
