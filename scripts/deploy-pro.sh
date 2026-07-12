#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="${VERCEL_PROJECT:-typo3-camino-vercel}"
scope="${VERCEL_SCOPE:-}"
stage="$(mktemp -d "${TMPDIR:-/tmp}/typo3-camino-vercel-pro.XXXXXX")"

cleanup() {
  rm -rf "${stage}"
}
trap cleanup EXIT

command -v vercel >/dev/null

if [[ -n "$(git -C "${root}" status --porcelain)" ]]; then
  echo "Commit or stash all changes before a Pro production deployment." >&2
  exit 1
fi

revision="$(git -C "${root}" rev-parse HEAD)"
branch="$(git -C "${root}" symbolic-ref --quiet --short HEAD || printf 'detached')"
message="$(git -C "${root}" log -1 --pretty=%s)"

git -C "${root}" archive HEAD | tar -xf - -C "${stage}"
cp "${stage}/vercel.pro.json" "${stage}/vercel.json"

if [[ -f "${root}/.vercel/project.json" ]]; then
  mkdir -p "${stage}/.vercel"
  cp "${root}/.vercel/project.json" "${stage}/.vercel/project.json"
fi

cmp -s "${stage}/vercel.pro.json" "${stage}/vercel.json"
echo "Staged Pro deployment for ${project} at ${revision}."

if [[ "${VERCEL_DEPLOY_DRY_RUN:-0}" == "1" ]]; then
  exit 0
fi

args=(
  deploy "${stage}"
  --prod
  --yes
  --project "${project}"
  --env "TYPO3_DEPLOYMENT_REVISION=${revision}"
  --meta "githubCommitSha=${revision}"
  --meta "githubCommitRef=${branch}"
  --meta "githubCommitMessage=${message}"
)

if [[ -n "${scope}" ]]; then
  args+=(--scope "${scope}")
fi

vercel "${args[@]}"

TYPO3_PUBLIC_BASE_URL="${TYPO3_PUBLIC_BASE_URL:-https://typo3-camino-vercel.vercel.app}" \
  "${root}/scripts/warm-public-pages.sh"
