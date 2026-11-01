#!/bin/sh
# One fresh PHP process per scan avoids retaining application/database state.
# The command handles five-minute freshness, failure cooldown and duplicate runners.
set -u

case "${ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC:-true}" in
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
    next_scan=$(( $(date +%s) + 60 ))
    if is_installed; then
        php /www/artisan external-subscriptions:sync --due --no-interaction
    fi
    wait_seconds=$(( next_scan - $(date +%s) ))
    [ "$wait_seconds" -gt 0 ] || wait_seconds=1
    sleep "$wait_seconds" &
    wait "$!"
done
