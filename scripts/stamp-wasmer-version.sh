#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 PLUGIN_DIRECTORY VERSION" >&2
    exit 2
fi

plugin_dir="$1"
version="$2"
plugin_file="${plugin_dir}/wp-wasmer.php"
readme_file="${plugin_dir}/readme.txt"

if [[ ! "${version}" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$ ]]; then
    echo "Cannot stamp Wasmer plugin: invalid semantic version: ${version}" >&2
    exit 1
fi

require_one_target() {
    local label="$1"
    local path="$2"
    local pattern="$3"
    local count

    if [[ ! -f "${path}" ]]; then
        echo "Cannot stamp Wasmer plugin: missing ${path}." >&2
        return 1
    fi

    count="$(grep -Ec "${pattern}" "${path}" || true)"
    if [[ "${count}" -ne 1 ]]; then
        echo "Cannot stamp Wasmer plugin: expected exactly one ${label} in ${path}, found ${count}." >&2
        return 1
    fi
}

header_pattern='^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*[^[:space:]]+[[:space:]]*$'
docblock_pattern='^[[:space:]]*\*[[:space:]]+@version[[:space:]]+[^[:space:]]+[[:space:]]*$'
constant_pattern="^[[:space:]]*define\\([[:space:]]*'WP_WASMER_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'[^']+'[[:space:]]*\\);[[:space:]]*$"
stable_tag_pattern='^[[:space:]]*Stable tag:[[:space:]]*[^[:space:]]+[[:space:]]*$'

require_one_target "plugin header Version" "${plugin_file}" "${header_pattern}"
require_one_target "plugin @version annotation" "${plugin_file}" "${docblock_pattern}"
require_one_target "WP_WASMER_PLUGIN_VERSION constant" "${plugin_file}" "${constant_pattern}"
require_one_target "WordPress.org Stable tag" "${readme_file}" "${stable_tag_pattern}"

sed -Ei \
    -e "s/^([[:space:]]*\\*[[:space:]]+Version:)[[:space:]]*[^[:space:]]+([[:space:]]*)$/\\1 ${version}\\2/" \
    -e "s/^([[:space:]]*\\*[[:space:]]+@version)[[:space:]]+[^[:space:]]+([[:space:]]*)$/\\1  ${version}\\2/" \
    -e "s|^[[:space:]]*define\\([[:space:]]*'WP_WASMER_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'[^']+'[[:space:]]*\\);[[:space:]]*$|define( 'WP_WASMER_PLUGIN_VERSION', '${version}' );|" \
    "${plugin_file}"

sed -Ei \
    "s/^([[:space:]]*Stable tag:)[[:space:]]*[^[:space:]]+([[:space:]]*)$/\\1 ${version}\\2/" \
    "${readme_file}"

echo "Stamped Wasmer plugin version ${version} in ${plugin_dir}."
