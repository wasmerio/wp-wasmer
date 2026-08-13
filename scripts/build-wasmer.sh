#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-${repo_root}/dist}"

if [[ ! -f "${repo_root}/version.txt" ]]; then
    echo "Could not determine the Wasmer release version: version.txt is missing." >&2
    exit 1
fi

mapfile -t version_lines < "${repo_root}/version.txt"
if [[ ${#version_lines[@]} -ne 1 || -z "${version_lines[0]}" ]]; then
    echo "Could not determine the Wasmer release version: version.txt must contain exactly one version." >&2
    exit 1
fi

version="${version_lines[0]}"
if [[ ! "${version}" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$ ]]; then
    echo "Could not determine the Wasmer release version: invalid semantic version in version.txt: ${version}" >&2
    exit 1
fi

if [[ -n "${WP_WASMER_RELEASE_TAG:-}" && "${WP_WASMER_RELEASE_TAG#v}" != "${version}" ]]; then
    echo "Wasmer release tag ${WP_WASMER_RELEASE_TAG} does not match version.txt (${version})." >&2
    exit 1
fi

for command_name in rsync zip unzip; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command_name}" >&2
        exit 1
    fi
done

stage_root="$(mktemp -d "${TMPDIR:-/tmp}/build-wasmer.XXXXXX")"
trap 'rm -rf -- "${stage_root}"' EXIT
plugin_dir="${stage_root}/wasmer"
archive="${output_dir}/wasmer.${version}.zip"

mkdir -p "${plugin_dir}" "${output_dir}"
install -m 0644 "${repo_root}/wp-wasmer.php" "${plugin_dir}/wp-wasmer.php"
install -m 0644 "${repo_root}/readme.txt" "${plugin_dir}/readme.txt"
install -m 0644 "${repo_root}/LICENSE" "${plugin_dir}/LICENSE"
rsync -a --exclude '/tests/' "${repo_root}/wasmer/" "${plugin_dir}/wasmer/"
"${repo_root}/scripts/stamp-wasmer-version.sh" "${plugin_dir}" "${version}"

if find "${plugin_dir}" -type d -name tests -print -quit | grep -q .; then
    echo "Refusing to package a tests directory." >&2
    exit 1
fi

find "${plugin_dir}" -exec touch -t 200001010000 {} +
rm -f -- "${archive}"
(
    cd "${stage_root}"
    find wasmer -type f -print | LC_ALL=C sort | zip -X -q "${archive}" -@
)

unzip -Z1 "${archive}" | grep -qx 'wasmer/wp-wasmer.php'
unzip -Z1 "${archive}" | grep -qx 'wasmer/readme.txt'
unzip -Z1 "${archive}" | grep -qx 'wasmer/LICENSE'
if unzip -Z1 "${archive}" | grep -Eq '(^|/)(tests?|node_modules)(/|$)'; then
    echo "Archive contains development or test files." >&2
    exit 1
fi

echo "Built ${archive}"
