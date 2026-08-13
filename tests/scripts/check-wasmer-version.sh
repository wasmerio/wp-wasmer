#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
checker="${repo_root}/scripts/check-wasmer-version.sh"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/check-wasmer-version.XXXXXX")"
trap 'rm -rf -- "${fixture_root}"' EXIT

write_fixture() {
    local version="$1"

    rm -rf -- "${fixture_root}/repo"
    mkdir -p "${fixture_root}/repo"
    printf '%s\n' "${version}" > "${fixture_root}/repo/version.txt"
    printf '{\n  ".": "%s"\n}\n' "${version}" > "${fixture_root}/repo/.release-please-manifest.json"
    printf '%s\n' \
        '<?php' \
        '/**' \
        " * Version: ${version}" \
        " * @version  ${version}" \
        ' */' \
        "define( 'WP_WASMER_PLUGIN_VERSION', '${version}' );" \
        > "${fixture_root}/repo/wp-wasmer.php"
    printf 'Stable tag: %s\n' "${version}" > "${fixture_root}/repo/readme.txt"
}

expect_failure() {
    local expected_message="$1"
    shift
    local output

    if output="$("${checker}" "$@" 2>&1)"; then
        echo "Expected version check to fail, but it succeeded." >&2
        exit 1
    fi

    if [[ "${output}" != *"${expected_message}"* ]]; then
        echo "Version check failed without the expected diagnostic: ${expected_message}" >&2
        echo "Actual output: ${output}" >&2
        exit 1
    fi
}

"${checker}"

write_fixture "1.2.3"
"${checker}" --root "${fixture_root}/repo" --expected "v1.2.3" >/dev/null

sed -i "s/WP_WASMER_PLUGIN_VERSION', '1.2.3'/WP_WASMER_PLUGIN_VERSION', '1.2.4'/" \
    "${fixture_root}/repo/wp-wasmer.php"
expect_failure \
    "wp-wasmer.php WP_WASMER_PLUGIN_VERSION is 1.2.4; expected 1.2.3" \
    --root "${fixture_root}/repo"

write_fixture "1.2.3"
expect_failure \
    "release tag/version is 1.2.4; expected 1.2.3" \
    --root "${fixture_root}/repo" --expected "v1.2.4"

write_fixture "not-a-version"
expect_failure \
    "version.txt contains an invalid semantic version" \
    --root "${fixture_root}/repo"

write_fixture "1.2.3"
printf 'Stable tag: 1.2.3\n' >> "${fixture_root}/repo/readme.txt"
expect_failure \
    "expected exactly one WordPress.org Stable tag" \
    --root "${fixture_root}/repo"

echo "Wasmer plugin version check tests passed."
