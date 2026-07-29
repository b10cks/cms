#!/bin/sh
set -eu

# Self-hosted convenience wrapper around supervisord. On SaaS/ECS every
# variable is provided and B10CKS_AUTO_SETUP is unset, so this is a strict
# pass-through.

# supervisord expands %(ENV_OCTANE_HOST)s at parse time and fails hard if the
# variable is missing, so it always has to be set. 127.0.0.1 keeps the SaaS/ECS
# behaviour; compose sets 0.0.0.0 so the published port is reachable.
export OCTANE_HOST="${OCTANE_HOST:-127.0.0.1}"

# APP_KEY: prefer the environment; otherwise generate once and persist it on
# the storage volume (/app is read-only for this user).
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE=/app/storage/app/setup/app.key
    mkdir -p "$(dirname "$KEY_FILE")"

    # The app and reverb containers share this volume and boot in parallel;
    # mkdir is atomic, so exactly one of them generates the key while the
    # other waits for the finished file. A generator that dies mid-run leaves
    # a stale lock, which a waiter reclaims after 60s.
    have_lock=0
    waited=0
    # -s (not -f): a failed earlier attempt must not leave an empty key file
    # that every later boot exports as APP_KEY.
    while [ ! -s "$KEY_FILE" ]; do
        if mkdir "$KEY_FILE.lock" 2>/dev/null; then
            have_lock=1
            break
        fi
        waited=$((waited + 1))
        if [ "$waited" -gt 60 ]; then
            echo "b10cks-entrypoint: reclaiming stale APP_KEY lock" >&2
            rm -rf "$KEY_FILE.lock"
        fi
        sleep 1
    done

    if [ "$have_lock" = 1 ]; then
        # Re-check under the lock, then write via unique tmp + mv so the
        # final file only ever appears complete.
        if [ ! -s "$KEY_FILE" ]; then
            php /app/artisan key:generate --show > "$KEY_FILE.tmp.$$"
            chmod 600 "$KEY_FILE.tmp.$$"
            mv "$KEY_FILE.tmp.$$" "$KEY_FILE"
        fi
        rmdir "$KEY_FILE.lock"
    fi

    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "${B10CKS_AUTO_SETUP:-false}" = "true" ]; then
    if [ ! -f /app/storage/app/setup/install-state.json ]; then
        php /app/artisan b10cks:setup --profile="${B10CKS_INSTALL_PROFILE:-standard}"
    else
        # Already installed: apply whatever the new image's migrations add, to
        # the management database and every space database. A no-op unless the
        # recorded version actually changed, so ordinary restarts pay nothing.
        #
        # Gated on B10CKS_AUTO_SETUP, which compose sets to false for the reverb
        # container — it shares this image and entrypoint, and two containers
        # migrating the same database concurrently on every upgrade is exactly
        # the race this avoids.
        php /app/artisan b10cks:upgrade
    fi
fi

exec /usr/bin/supervisord -c "${B10CKS_SUPERVISORD_CONF:-/etc/supervisord.conf}" -n
