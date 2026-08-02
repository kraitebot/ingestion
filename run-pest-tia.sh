#!/usr/bin/env bash

set -euo pipefail

has_explicit_scope=false
has_fresh_graph=false

for argument in "$@"; do
    case "${argument}" in
        --fresh)
            has_fresh_graph=true
            ;;
        tests/*|--filter|--filter=*|--testsuite|--testsuite=*|--group|--group=*|--covers|--covers=*)
            has_explicit_scope=true
            ;;
    esac
done

if [[ "${has_explicit_scope}" == false && "${has_fresh_graph}" == false ]]; then
    path_dependencies=(
        ../packages/kraitebot/core
        ../packages/brunocfalcao/step-dispatcher
        ../packages/brunocfalcao/blade-feather-icons
        ../packages/brunocfalcao/laravel-helpers
    )

    for path_dependency in "${path_dependencies[@]}"; do
        if [[ -n "$(git -C "${path_dependency}" status --porcelain --untracked-files=all 2>/dev/null)" ]]; then
            echo "Dirty path dependency detected (${path_dependency}); rebuilding the complete TIA graph." >&2
            set -- --fresh "$@"
            break
        fi
    done
fi

if [[ "${CI:-}" =~ ^(1|true)$ ]]; then
    exec php ./vendor/bin/pest --no-tia "$@"
fi

if command -v herd >/dev/null 2>&1 && herd list --raw 2>/dev/null | grep -q '^coverage '; then
    php_version="$(php -r 'echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;')"
    herd_ini="${HOME}/Library/Application Support/Herd/config/php/${php_version}/php.ini"

    if [[ -f "${herd_ini}" ]]; then
        PHP_INI_SCAN_DIR="$(dirname "${herd_ini}")"
        export PHP_INI_SCAN_DIR
        exec herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
    fi

    exec herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

if php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'; then
    export XDEBUG_MODE=coverage
    exec php -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

echo 'Pest TIA requires PCOV or Xdebug coverage. Install a coverage driver or run through Laravel Herd.' >&2
exit 1
