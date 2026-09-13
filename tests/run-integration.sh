#!/usr/bin/env bash
# Requires a disposable MySQL/MariaDB server; never uses an existing database.
set -euo pipefail
cd "$(dirname "$0")/.."
export PF_TEST_ROOT
PF_TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/petit-form-test.XXXXXX")
export PF_TEST_DB="petit_form_test_${RANDOM}_$$"
cleanup() {
  result=$?
  if [[ "$result" != 0 && -f "$PF_TEST_ROOT/wordpress/wp-content/debug.log" ]]; then
    tail -n 50 "$PF_TEST_ROOT/wordpress/wp-content/debug.log" >&2
  fi
  php tests/bootstrap.php cleanup || result=1
  rm -rf "$PF_TEST_ROOT"
  exit "$result"
}
trap cleanup EXIT
version=${1:-latest}
if [[ "$version" == latest ]]; then
  url=https://wordpress.org/latest.zip
elif [[ "$version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
  url="https://wordpress.org/wordpress-${version}.zip"
else
  exit 1
fi
curl --fail --silent --show-error --location --retry 2 "$url" -o "$PF_TEST_ROOT/wordpress.zip"
php tests/bootstrap.php install
php tests/integration.php
