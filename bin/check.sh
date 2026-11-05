#!/usr/bin/env bash
# Checks this package: PHP syntax, composer.json, version consistency.
#   bin/check.sh    (needs php and composer, e.g. in ghcr.io/kopexa-grc/php-apache:8.4-dev)
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
err() { echo "ERROR: $*"; status=1; }

while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || err "syntax error in $f"
done < <(find . -path ./vendor -prune -o -name '*.php' -print0)

version="$(tr -d '[:space:]' < VERSION)"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || err "VERSION '$version' is not x.y.z"
php -r 'exit(array_key_exists("version", json_decode(file_get_contents("composer.json"), true)) ? 1 : 0);' \
  || err 'composer.json must not contain "version" (Packagist takes it from git tags)'
grep -qE "^## \[?${version//./\\.}[] ]" CHANGELOG.md || err "CHANGELOG.md has no entry for $version"
# shellcheck disable=SC2016  # PHP code, not shell
plugin="$(php -r '$c = require "Config/config.php"; echo $c["version"] ?? "";')"
[[ "$plugin" == "$version" ]] || err "Config/config.php version '$plugin' != VERSION '$version'"
COMPOSER_ROOT_VERSION="$version" composer validate --strict --no-check-lock --no-check-publish >/dev/null \
  || err "composer validate failed"
php Tests/selftest.php || err "self-test failed"

[[ $status == 0 ]] && echo "ok: mautic-preferences-center $version"
exit $status
