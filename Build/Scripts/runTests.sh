#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
selector="all"

while getopts "s:" option; do
  case "${option}" in
    s) selector="${OPTARG}" ;;
    *) exit 2 ;;
  esac
done

run_unit() {
  "${root}/vendor/bin/phpunit" --testsuite unit
}

run_lint() {
  grep -Fq 'return typo3_vercel_settings();' "${root}/config/system/settings.php"
  find \
    "${root}/Tests" \
    "${root}/config" \
    "${root}/packages" \
    "${root}/public/api" \
    "${root}/scripts" \
    -type f -name '*.php' -print0 \
    | sort -z \
    | xargs -0 -n1 php -l >/dev/null
  php -l "${root}/public/index.php" >/dev/null
  bash -n "${root}/docker/entrypoint.sh" "${root}/docker/serve.sh" "${root}/services/solr/start-vercel-solr.sh"
  php -r 'foreach (array_slice($argv, 1) as $file) { json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }' \
    "${root}/composer.json" "${root}/vercel.json" "${root}/vercel.pro.json"
  php -r '$dom = new DOMDocument(); foreach (array_slice($argv, 1) as $file) { if (!$dom->load($file)) { exit(1); } }' \
    "${root}/phpunit.xml" \
    "${root}/packages/typo3-vercel-blob-storage/Configuration/Resource/Driver/BlobDriverFlexForm.xml" \
    "${root}/packages/typo3-vercel-storage/Configuration/Resource/Driver/S3DriverFlexForm.xml"
  composer validate --strict --no-interaction
}

run_containers() {
  docker build -f "${root}/Dockerfile.vercel" -t typo3-camino-vercel:test "${root}"
  docker build -f "${root}/services/solr/Dockerfile.vercel" -t typo3-camino-solr:test "${root}/services/solr"
}

case "${selector}" in
  unit) run_unit ;;
  lint) run_lint ;;
  containers) run_containers ;;
  all)
    run_lint
    run_unit
    ;;
  *)
    echo "Unknown selector: ${selector}" >&2
    echo "Use: all, unit, lint, or containers" >&2
    exit 2
    ;;
esac
