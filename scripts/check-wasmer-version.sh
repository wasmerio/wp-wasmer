#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
expected_version=""

usage() {
    echo "Usage: $0 [--expected VERSION_OR_TAG] [--root REPOSITORY_ROOT]" >&2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --expected)
            if [[ $# -lt 2 ]]; then
                usage
                exit 2
            fi
            expected_version="$2"
            shift 2
            ;;
        --root)
            if [[ $# -lt 2 ]]; then
                usage
                exit 2
            fi
            repo_root="$2"
            shift 2
            ;;
        *)
            usage
            exit 2
            ;;
    esac
done

extract_one() {
    local label="$1"
    local relative_path="$2"
    local expression="$3"
    local path="${repo_root}/${relative_path}"
    local values=()

    if [[ ! -f "${path}" ]]; then
        echo "Version check failed: ${label} file is missing: ${relative_path}" >&2
        return 1
    fi

    mapfile -t values < <(sed -nE "${expression}" "${path}")
    if [[ ${#values[@]} -ne 1 || -z "${values[0]}" ]]; then
        echo "Version check failed: expected exactly one ${label} in ${relative_path}, found ${#values[@]}." >&2
        return 1
    fi

    printf '%s\n' "${values[0]}"
}

version_file="$(extract_one \
    "release version" \
    "version.txt" \
    's/^([^[:space:]]+)[[:space:]]*$/\1/p')" || exit 1

manifest_version="$(extract_one \
    "release-please manifest version" \
    ".release-please-manifest.json" \
    's/^[[:space:]]*"\."[[:space:]]*:[[:space:]]*"([^"]+)"[,]?[[:space:]]*$/\1/p')" || exit 1

plugin_header_version="$(extract_one \
    "WordPress plugin header Version" \
    "wp-wasmer.php" \
    's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')" || exit 1

plugin_docblock_version="$(extract_one \
    "plugin @version annotation" \
    "wp-wasmer.php" \
    's/^[[:space:]]*\*[[:space:]]+@version[[:space:]]+([^[:space:]]+)[[:space:]]*$/\1/p')" || exit 1

plugin_constant_version="$(extract_one \
    "WP_WASMER_PLUGIN_VERSION constant" \
    "wp-wasmer.php" \
    "s/^[[:space:]]*define\\([[:space:]]*'WP_WASMER_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\);[[:space:]]*$/\\1/p")" || exit 1

readme_version="$(extract_one \
    "WordPress.org Stable tag" \
    "readme.txt" \
    's/^[[:space:]]*Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p')" || exit 1

if [[ ! "${version_file}" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$ ]]; then
    echo "Version check failed: version.txt contains an invalid semantic version: ${version_file}" >&2
    exit 1
fi

declare -a sources=(
    ".release-please-manifest.json:${manifest_version}"
    "wp-wasmer.php plugin header:${plugin_header_version}"
    "wp-wasmer.php @version:${plugin_docblock_version}"
    "wp-wasmer.php WP_WASMER_PLUGIN_VERSION:${plugin_constant_version}"
    "readme.txt Stable tag:${readme_version}"
)

failed=0
for source in "${sources[@]}"; do
    label="${source%%:*}"
    value="${source#*:}"
    if [[ "${value}" != "${version_file}" ]]; then
        echo "Version check failed: ${label} is ${value}; expected ${version_file} from version.txt." >&2
        failed=1
    fi
done

if [[ -n "${expected_version}" ]]; then
    expected_version="${expected_version#v}"
    if [[ "${expected_version}" != "${version_file}" ]]; then
        echo "Version check failed: release tag/version is ${expected_version}; expected ${version_file} from version.txt." >&2
        failed=1
    fi
fi

if [[ ${failed} -ne 0 ]]; then
    exit 1
fi

echo "Wasmer plugin version ${version_file} is consistent."
