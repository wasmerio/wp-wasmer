#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/tests/e2e/docker-compose.yml"
PROJECT_NAME="${WP_WASMER_E2E_PROJECT:-wp-wasmer-migration-e2e}"
WP_VERSION="${WP_VERSION:-6.8.2}"
PHP_VERSION="${PHP_VERSION:-8.3}"
MARKER="marker-$(date +%s)-$RANDOM"
SOURCE_TABLE_PREFIX="${SOURCE_TABLE_PREFIX:-src_}"
TARGET_TABLE_PREFIX="${TARGET_TABLE_PREFIX:-tgt_}"
export WP_VERSION PHP_VERSION

compose() {
  docker compose -p "$PROJECT_NAME" -f "$COMPOSE_FILE" "$@"
}

wp_source() {
  compose run --rm source-cli "$@"
}

wp_target() {
  compose run --rm target-cli "$@"
}

wait_for_wp() {
  local site="$1"
  local tries=0
  until "wp_$site" core version >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 90 ]; then
      echo "Timed out waiting for $site WordPress files" >&2
      compose logs "$site"
      exit 1
    fi
    sleep 2
  done
}

install_site() {
  local site="$1"
  local url="$2"
  local title="$3"

  if ! "wp_$site" core is-installed >/dev/null 2>&1; then
    "wp_$site" core install \
      --url="$url" \
      --title="$title" \
      --admin_user=admin \
      --admin_password=password \
      --admin_email=admin@example.com \
      --skip-email
  fi
}

set_table_prefix() {
  local site="$1"
  local prefix="$2"

  compose exec -T "$site" sh -s -- "$prefix" <<'EOS'
set -eu
prefix="$1"
mkdir -p /var/www/html/wp-content
printf "%s\n" "<?php" "\$table_prefix = '${prefix}';" > /var/www/html/wp-content/wp-config-extra.php
if ! grep -q 'Wasmer E2E additional config after table prefix' /var/www/html/wp-config.php; then
  awk '
    /\/\* That'\''s all, stop editing! Happy publishing\. \*\// {
      print "// Wasmer E2E additional config after table prefix";
      print "if (getenv('\''WP_ADDITIONAL_CONFIG'\'') && file_exists(getenv('\''WP_ADDITIONAL_CONFIG'\''))) {";
      print "    require getenv('\''WP_ADDITIONAL_CONFIG'\'');";
      print "}";
      print "";
    }
    { print }
  ' /var/www/html/wp-config.php > /tmp/wp-config.php
  cat /tmp/wp-config.php > /var/www/html/wp-config.php
fi
chmod 0444 /var/www/html/wp-config.php
EOS
  local actual
  actual="$("wp_$site" config get table_prefix)"
  if [ "$actual" != "$prefix" ]; then
    echo "Expected $site table prefix $prefix from wp-content config, got $actual" >&2
    exit 1
  fi
}

wp_config_hash() {
  local site="$1"

  compose exec -T "$site" sh -lc "sha256sum /var/www/html/wp-config.php | awk '{print \$1}'"
}

cleanup() {
  if [ "${WP_WASMER_E2E_KEEP:-0}" != "1" ]; then
    compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

cd "$ROOT_DIR"

echo "Running Wasmer WordPress migration E2E with WordPress $WP_VERSION and PHP $PHP_VERSION"

compose down -v --remove-orphans >/dev/null 2>&1 || true
compose up -d db source target

wait_for_wp source
wait_for_wp target

set_table_prefix source "$SOURCE_TABLE_PREFIX"
set_table_prefix target "$TARGET_TABLE_PREFIX"

install_site source http://source "Wasmer Migration Source"
install_site target http://target "Wasmer Migration Target"
wp_target user update admin --user_pass=target-admin-pass

target_wp_config_before="$(wp_config_hash target)"

wp_source plugin activate wasmer-migrate
wp_target plugin activate wp-wasmer
wp_target user create target-editor target-editor@example.com --user_pass=targetpass --role=editor --display_name="Target Editor"
wp_target user meta update target-editor wasmer_migration_preserve_user_meta target-user-meta

wp_source eval-file /e2e/seed-source.php "$MARKER"

session_json="$(wp_target wasmer import session create --expires=2h)"
import_code="$(printf '%s' "$session_json" | python3 -c 'import json, sys; data=json.load(sys.stdin); print(data["code"])')"
session_id="$(printf '%s' "$session_json" | python3 -c 'import json, sys; data=json.load(sys.stdin); print(data["session"]["id"])')"

wp_source wasmer-migrate connect "$import_code"
wp_source wasmer-migrate plan
wp_source wasmer-migrate start
wp_source --skip-plugins eval-file /e2e/validate-staging-guards.php migrate
wp_target eval-file /e2e/force-source-version.php "$session_id" 7.0

wp_target wasmer import start "$session_id"
target_wp_config_after="$(wp_config_hash target)"
if [ "$target_wp_config_before" != "$target_wp_config_after" ]; then
  echo "Target root wp-config.php changed during import." >&2
  exit 1
fi
wp_target --skip-plugins eval-file /e2e/validate-target.php "$MARKER" "$SOURCE_TABLE_PREFIX" "$TARGET_TABLE_PREFIX"
wp_target --skip-plugins eval-file /e2e/validate-staging-guards.php import "$session_id"

echo "Wasmer WordPress migration E2E passed for session $session_id"
