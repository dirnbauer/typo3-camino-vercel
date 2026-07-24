#!/usr/bin/env bash
set -euo pipefail

interval="${TYPO3_SCHEDULER_LOOP_INTERVAL:-60}"
if ! [[ "$interval" =~ ^[0-9]+$ ]] || [ "$interval" -lt 60 ]; then
  echo "TYPO3_SCHEDULER_LOOP_INTERVAL must be an integer of at least 60 seconds." >&2
  exit 1
fi

while true; do
  started_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "Running TYPO3 Scheduler at ${started_at}"
  if ! vendor/bin/typo3 scheduler:run --no-interaction; then
    echo "TYPO3 Scheduler failed; the next run will retry in ${interval}s." >&2
  fi
  sleep "$interval"
done
