#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-${repo_root}/dist}"
version="$(sed -n "s/^define('WASMER_MIGRATE_VERSION', '\([^']*\)');$/\1/p" "${repo_root}/wasmer-migrate/wasmer-migrate.php")"

if [[ -z "${version}" ]]; then
    echo "Could not determine the Wasmer Migrate plugin version." >&2
    exit 1
fi

for command_name in rsync zip unzip; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command_name}" >&2
        exit 1
    fi
done

stage_root="$(mktemp -d "${TMPDIR:-/tmp}/build-wasmer-migrate.XXXXXX")"
trap 'rm -rf -- "${stage_root}"' EXIT
plugin_dir="${stage_root}/wasmer-migrate"
archive="${output_dir}/wasmer-migrate.${version}.zip"

mkdir -p "${plugin_dir}" "${output_dir}"
rsync -a \
    --exclude '/tests/' \
    --exclude '/node_modules/' \
    "${repo_root}/wasmer-migrate/" \
    "${plugin_dir}/"
install -m 0644 "${repo_root}/LICENSE" "${plugin_dir}/LICENSE"

if find "${plugin_dir}" -type d \( -name tests -o -name node_modules \) -print -quit | grep -q .; then
    echo "Refusing to package development or test directories." >&2
    exit 1
fi

find "${plugin_dir}" -exec touch -t 200001010000 {} +
rm -f -- "${archive}"
(
    cd "${stage_root}"
    find wasmer-migrate -type f -print | LC_ALL=C sort | zip -X -q "${archive}" -@
)

unzip -Z1 "${archive}" | grep -qx 'wasmer-migrate/wasmer-migrate.php'
unzip -Z1 "${archive}" | grep -qx 'wasmer-migrate/readme.txt'
unzip -Z1 "${archive}" | grep -qx 'wasmer-migrate/LICENSE'
if unzip -Z1 "${archive}" | grep -Eq '(^|/)(tests?|node_modules)(/|$)'; then
    echo "Archive contains development or test files." >&2
    exit 1
fi

echo "Built ${archive}"

