#!/bin/sh
set -eu

: "${TYPO3_PUBLIC_URL:?Set TYPO3_PUBLIC_URL to the public application origin}"
: "${CRON_SECRET:?Set the same CRON_SECRET as the application service}"

case "${TYPO3_PUBLIC_URL}" in
  https://*|http://*) ;;
  *)
    echo "TYPO3_PUBLIC_URL must start with https:// or http://." >&2
    exit 2
    ;;
esac

exec curl \
  --fail \
  --show-error \
  --silent \
  --max-time 240 \
  --header "Authorization: Bearer ${CRON_SECRET}" \
  "${TYPO3_PUBLIC_URL%/}/api/cron/typo3-scheduler.php"
