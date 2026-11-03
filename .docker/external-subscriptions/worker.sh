#!/bin/sh
# One fresh PHP process per scan avoids retaining application/database state.
# Synchronization and health probing have independent workers and time budgets.
set -u

case "${1:-sync}" in
    sync) enabled=${ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC:-true}; interval=60
        set -- external-subscriptions:sync --due ;;
    health) enabled=${ENABLE_EXTERNAL_SUBSCRIPTIONS_HEALTH:-true}; interval=15
        case "${2:-}" in 0|1) slot=$2 ;; *) exit 2 ;; esac
        set -- external-subscriptions:health "--slot=$slot" ;;
    *) exit 2 ;;
esac
case "$enabled" in
    1|true|TRUE|yes|YES|on|ON) ;;
    *) exit 0 ;;
esac

is_installed() {
    case "${INSTALLED-}" in
        1|true|TRUE) return 0 ;;
        0|false|FALSE) return 1 ;;
    esac
    [ -r /www/.env ] && grep -Eiq "^[[:space:]]*INSTALLED[[:space:]]*=[[:space:]]*[\"']?(true|1)[\"']?([[:space:]]*(#.*)?)?$" /www/.env
}

trap 'exit 0' TERM INT
while :; do
    next_scan=$(( $(date +%s) + interval ))
    if is_installed; then
        php /www/artisan "$@" --no-interaction
    fi
    wait_seconds=$(( next_scan - $(date +%s) ))
    [ "$wait_seconds" -gt 0 ] || wait_seconds=1
    sleep "$wait_seconds" &
    wait "$!"
done
