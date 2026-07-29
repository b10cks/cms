#!/bin/sh
set -eu

# Self-hosted convenience wrapper around supervisord. On SaaS/ECS every
# variable is provided and B10CKS_AUTO_SETUP is unset, so this is a strict
# pass-through.

# APP_KEY: prefer the environment; otherwise generate once and persist it on
# the storage volume (/app is read-only for this user).
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE=/app/storage/app/setup/app.key
    # -s (not -f): a failed earlier attempt must not leave an empty key file
    # that every later boot exports as APP_KEY. Write via tmp + mv so the
    # final file is only ever complete.
    if [ ! -s "$KEY_FILE" ]; then
        mkdir -p "$(dirname "$KEY_FILE")"
        php /app/artisan key:generate --show > "$KEY_FILE.tmp"
        chmod 600 "$KEY_FILE.tmp"
        mv "$KEY_FILE.tmp" "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "${B10CKS_AUTO_SETUP:-false}" = "true" ] && [ ! -f /app/storage/app/setup/install-state.json ]; then
    php /app/artisan b10cks:setup --profile="${B10CKS_INSTALL_PROFILE:-standard}"
fi

exec /usr/bin/supervisord -c "${B10CKS_SUPERVISORD_CONF:-/etc/supervisord.conf}" -n
