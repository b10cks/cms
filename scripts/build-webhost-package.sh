#!/usr/bin/env bash
#
# Builds the release package for traditional webhosts (no docker, no
# composer/node): a staged tree with vendor/ and the pre-built frontend baked
# in, emitted as .tar.gz (primary) and .zip. Run after:
#   composer install --no-dev --optimize-autoloader && bun run build

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-${APP_VERSION:-latest}}"
DIST_DIR="$ROOT_DIR/dist"
PACKAGE_NAME="b10cks-cms-webhost-${VERSION}"
STAGING_ROOT="$(mktemp -d)"
PACKAGE_DIR="$STAGING_ROOT/$PACKAGE_NAME"
TGZ_PATH="$DIST_DIR/${PACKAGE_NAME}.tar.gz"
ZIP_PATH="$DIST_DIR/${PACKAGE_NAME}.zip"

if [ ! -f "$ROOT_DIR/public/build/manifest.json" ]; then
  echo "error: public/build/manifest.json missing — run 'bun run build' first" >&2
  exit 1
fi

if [ ! -d "$ROOT_DIR/vendor" ]; then
  echo "error: vendor/ missing — run 'composer install --no-dev' first" >&2
  exit 1
fi

mkdir -p "$DIST_DIR" "$PACKAGE_DIR"

for path in app bootstrap config database public resources routes vendor artisan composer.json composer.lock; do
  rsync -a "$ROOT_DIR/$path" "$PACKAGE_DIR/"
done

# A cached config (php artisan config:cache) contains the fully resolved .env
# of the build machine — secrets included. It must never ship, and a baked
# cache would override the operator's .env anyway.
rm -f "$PACKAGE_DIR"/bootstrap/cache/*.php

# Webhost-tuned environment template ships as the package's .env.example.
cp "$ROOT_DIR/.env.webhost.example" "$PACKAGE_DIR/.env.example"

# Writable tree (git only tracks .gitignore placeholders).
mkdir -p \
  "$PACKAGE_DIR/storage/app/private" \
  "$PACKAGE_DIR/storage/app/public" \
  "$PACKAGE_DIR/storage/app/setup" \
  "$PACKAGE_DIR/storage/app/spaces" \
  "$PACKAGE_DIR/storage/app/transfers" \
  "$PACKAGE_DIR/storage/framework/cache/data" \
  "$PACKAGE_DIR/storage/framework/sessions" \
  "$PACKAGE_DIR/storage/framework/views" \
  "$PACKAGE_DIR/storage/logs" \
  "$PACKAGE_DIR/bootstrap/cache"

# No dev-server leftovers: a stale hot file makes the app load assets from a
# Vite server that isn't there.
rm -f "$PACKAGE_DIR/public/hot"

# Match the repo's public/storage symlink (what the app serves in production).
rm -rf "$PACKAGE_DIR/public/storage"
ln -s ../storage/app/private "$PACKAGE_DIR/public/storage"

# Arm the HTTP installer: GET /setup works out of the box and the marker
# deletes itself after a successful run.
touch "$PACKAGE_DIR/storage/app/setup/http-enabled"

cp "$ROOT_DIR/docs/self-hosting/installation.md" "$PACKAGE_DIR/README.md"

rm -f "$TGZ_PATH" "$ZIP_PATH"
(
  cd "$STAGING_ROOT"
  tar -czf "$TGZ_PATH" "$PACKAGE_NAME"
  zip -rqy "$ZIP_PATH" "$PACKAGE_NAME"
)
rm -rf "$STAGING_ROOT"

echo "$TGZ_PATH"
echo "$ZIP_PATH"
