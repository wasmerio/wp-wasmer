#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
build_script="${repo_root}/scripts/build-wasmer.sh"
stamp_script="${repo_root}/scripts/stamp-wasmer-version.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/build-wasmer-version.XXXXXX")"
trap 'rm -rf -- "${test_root}"' EXIT

expect_failure() {
    local expected_message="$1"
    shift
    local output

    if output="$("$@" 2>&1)"; then
        echo "Expected command to fail, but it succeeded: $*" >&2
        exit 1
    fi

    if [[ "${output}" != *"${expected_message}"* ]]; then
        echo "Command failed without the expected diagnostic: ${expected_message}" >&2
        echo "Actual output: ${output}" >&2
        exit 1
    fi
}

extract_one() {
    local label="$1"
    local path="$2"
    local expression="$3"
    local values=()

    mapfile -t values < <(sed -nE "${expression}" "${path}")
    if [[ ${#values[@]} -ne 1 || -z "${values[0]}" ]]; then
        echo "Expected exactly one ${label} in ${path}, found ${#values[@]}." >&2
        exit 1
    fi

    printf '%s\n' "${values[0]}"
}

release_version="$(tr -d '\r\n' < "${repo_root}/version.txt")"
development_header_version="$(extract_one "development plugin header Version" "${repo_root}/wp-wasmer.php" 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')"
development_docblock_version="$(extract_one "development plugin @version" "${repo_root}/wp-wasmer.php" 's/^[[:space:]]*\*[[:space:]]+@version[[:space:]]+([^[:space:]]+)[[:space:]]*$/\1/p')"
development_constant_version="$(extract_one "development plugin version constant" "${repo_root}/wp-wasmer.php" "s/^[[:space:]]*define\\([[:space:]]*'WP_WASMER_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\);[[:space:]]*$/\\1/p")"
development_stable_tag="$(extract_one "development Stable tag" "${repo_root}/readme.txt" 's/^[[:space:]]*Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')"
for development_version in \
    "${development_header_version}" \
    "${development_docblock_version}" \
    "${development_constant_version}" \
    "${development_stable_tag}"; do
    if [[ "${development_version}" != "0.0.0" ]]; then
        echo "Tracked plugin sources must use the fixed 0.0.0 development version." >&2
        exit 1
    fi
done

WP_WASMER_RELEASE_TAG="v${release_version}" "${build_script}" "${test_root}/dist" >/dev/null

archive="${test_root}/dist/wasmer.${release_version}.zip"
unzip -q "${archive}" -d "${test_root}/archive"
plugin_file="${test_root}/archive/wasmer/wp-wasmer.php"
readme_file="${test_root}/archive/wasmer/readme.txt"

header_version="$(extract_one "plugin header Version" "${plugin_file}" 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')"
docblock_version="$(extract_one "plugin @version" "${plugin_file}" 's/^[[:space:]]*\*[[:space:]]+@version[[:space:]]+([^[:space:]]+)[[:space:]]*$/\1/p')"
constant_version="$(extract_one "plugin version constant" "${plugin_file}" "s/^[[:space:]]*define\\([[:space:]]*'WP_WASMER_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\);[[:space:]]*$/\\1/p")"
stable_tag_version="$(extract_one "Stable tag" "${readme_file}" 's/^[[:space:]]*Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')"

for stamped_version in "${header_version}" "${docblock_version}" "${constant_version}" "${stable_tag_version}"; do
    if [[ "${stamped_version}" != "${release_version}" ]]; then
        echo "Archive contains version ${stamped_version}; expected ${release_version}." >&2
        exit 1
    fi
done

mkdir -p "${test_root}/fixture"
cp "${repo_root}/wp-wasmer.php" "${repo_root}/readme.txt" "${test_root}/fixture/"

expect_failure "Usage:" "${stamp_script}"
expect_failure "invalid semantic version" "${stamp_script}" "${test_root}/fixture" "not-a-version"
expect_failure "missing ${test_root}/missing/wp-wasmer.php" "${stamp_script}" "${test_root}/missing" "1.2.3"

sed -i '/@version/d' "${test_root}/fixture/wp-wasmer.php"
expect_failure "expected exactly one plugin @version annotation" "${stamp_script}" "${test_root}/fixture" "1.2.3"

cp "${repo_root}/wp-wasmer.php" "${repo_root}/readme.txt" "${test_root}/fixture/"
printf 'Stable tag: 0.0.0\n' >> "${test_root}/fixture/readme.txt"
expect_failure "expected exactly one WordPress.org Stable tag" "${stamp_script}" "${test_root}/fixture" "1.2.3"

expect_failure \
    "does not match version.txt" \
    env WP_WASMER_RELEASE_TAG=v99.99.99 "${build_script}" "${test_root}/mismatch"

mkdir -p "${test_root}/missing-version/scripts" "${test_root}/invalid-version/scripts"
cp "${build_script}" "${test_root}/missing-version/scripts/"
cp "${build_script}" "${test_root}/invalid-version/scripts/"
printf 'not-a-version\n' > "${test_root}/invalid-version/version.txt"

expect_failure \
    "version.txt is missing" \
    "${test_root}/missing-version/scripts/build-wasmer.sh" "${test_root}/missing-version/dist"
expect_failure \
    "invalid semantic version in version.txt" \
    "${test_root}/invalid-version/scripts/build-wasmer.sh" "${test_root}/invalid-version/dist"

echo "Wasmer release-version build tests passed."
