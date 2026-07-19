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

run_phpstan() {
  "${root}/vendor/bin/phpstan" analyse --no-progress --memory-limit=1G
}

# macOS ships bash 3.2, which lacks mapfile and namerefs; fill one global
# array with NUL-delimited paths instead.
collect_files() {
  collected_files=()
  while IFS= read -r -d '' file; do
    collected_files+=("${file}")
  done < <("$@")
}

find_tracked() {
  find "${root}" \
    \( \
      -path "${root}/.git" \
      -o -path "${root}/.claude" \
      -o -path "${root}/.vercel" \
      -o -path "${root}/node_modules" \
      -o -path "${root}/var" \
      -o -path "${root}/vendor" \
    \) -prune \
    -o -type f "$@" -print0
}

run_lint() {
  grep -Fq 'return typo3_vercel_settings();' "${root}/config/system/settings.php"
  php "${root}/scripts/restore-project-files.php" --check
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
  "${root}/vendor/bin/typo3" setup --help >/dev/null
  bash -n \
    "${root}/docker/entrypoint.sh" \
    "${root}/docker/serve.sh" \
    "${root}/scripts/deploy-pro.sh" \
    "${root}/services/solr/start-vercel-solr.sh"

  collect_files find_tracked -name '*.json'
  # PHP, not the shell, expands $argv and $file.
  # shellcheck disable=SC2016
  php -r 'foreach (array_slice($argv, 1) as $file) { json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }' "${collected_files[@]}"

  collect_files find_tracked \( -name '*.xml' -o -name '*.xlf' \)
  # PHP, not the shell, expands $dom, $argv, and $file.
  # shellcheck disable=SC2016
  php -r '$dom = new DOMDocument(); foreach (array_slice($argv, 1) as $file) { if (!$dom->load($file)) { exit(1); } }' "${collected_files[@]}"

  collect_files find_tracked \( -name '*.yaml' -o -name '*.yml' \)
  # PHP, not the shell, expands $argv and $file.
  # shellcheck disable=SC2016
  php -r 'require $argv[1]; foreach (array_slice($argv, 2) as $file) { Symfony\Component\Yaml\Yaml::parseFile($file); }' \
    "${root}/vendor/autoload.php" "${collected_files[@]}"

  composer validate --strict --no-interaction
}

run_containers() {
  docker build -f "${root}/Dockerfile.vercel" -t typo3-camino-vercel:test "${root}"
  docker build -f "${root}/services/solr/Dockerfile.vercel" -t typo3-camino-solr:test "${root}/services/solr"
}

case "${selector}" in
  unit) run_unit ;;
  phpstan) run_phpstan ;;
  lint) run_lint ;;
  containers) run_containers ;;
  all)
    run_lint
    run_phpstan
    run_unit
    ;;
  *)
    echo "Unknown selector: ${selector}" >&2
    echo "Use: all, unit, phpstan, lint, or containers" >&2
    exit 2
    ;;
esac
