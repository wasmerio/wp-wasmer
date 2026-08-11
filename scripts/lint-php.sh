#!/usr/bin/env bash

set -uo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
phpcs_bin="${repo_root}/vendor/bin/phpcs"

if ! command -v php >/dev/null 2>&1; then
    echo "Required command is missing: php" >&2
    exit 1
fi

if [[ ! -x "${phpcs_bin}" ]]; then
    echo "PHP_CodeSniffer is not installed. Run 'composer install' in the repository root." >&2
    exit 1
fi

syntax_status=0
while IFS= read -r -d '' php_file; do
    if ! php -l "${php_file}"; then
        syntax_status=1
    fi
done < <(
    find "${repo_root}" \
        -type d \( \
            -name .git -o \
            -name build -o \
            -name dist -o \
            -name node_modules -o \
            -name vendor \
        \) -prune -o \
        -type f -name '*.php' -print0
)

if (( syntax_status != 0 )); then
    echo "PHP syntax lint failed." >&2
    exit "${syntax_status}"
fi

cd -- "${repo_root}" || exit 1
exec "${phpcs_bin}" --standard="${repo_root}/phpcs.xml.dist"
