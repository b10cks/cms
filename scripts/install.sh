#!/bin/sh
# b10cks CMS installer — https://get.b10cks.com
#
#   curl -fsSL https://get.b10cks.com | sh
#
# Fetches the compose stack for the newest release, generates the secrets that
# the .env template leaves as placeholders, and brings the stack up. Everything
# is written into ./b10cks (override with B10CKS_DIR); nothing is installed
# outside that directory.
#
# Environment overrides:
#   B10CKS_DIR      target directory              (default: ./b10cks)
#   B10CKS_VERSION  release tag to install        (default: newest release)
#   B10CKS_PORT     host port for the app         (default: 8000)
set -eu

# The script is piped into sh, so stdin is the script itself and prompting is
# impossible. Every decision has to come from a flag, an env var, or a default.
say() { printf '  %s\n' "$1"; }
step() { printf '\n\033[1m==>\033[0m %s\n' "$1"; }
die() { printf '\n\033[31merror:\033[0m %s\n\n' "$1" >&2; exit 1; }

# openssl is not guaranteed on minimal hosts; /dev/urandom always is.
rand() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 24
    else
        LC_ALL=C tr -dc 'a-f0-9' < /dev/urandom | head -c 48
    fi
}

main() {
    REPO="b10cks/cms"
    RAW="https://raw.githubusercontent.com/${REPO}"
    DIR="${B10CKS_DIR:-./b10cks}"
    PORT="${B10CKS_PORT:-8000}"

    step "Checking prerequisites"

    command -v docker >/dev/null 2>&1 || die "docker is not installed. See https://docs.docker.com/get-docker/"
    docker compose version >/dev/null 2>&1 || die "the docker compose plugin is missing. Install Docker Compose v2."
    docker info >/dev/null 2>&1 || die "the docker daemon is not reachable. Start Docker and retry."

    # Images are published for amd64 and arm64 only. Without this check an
    # unsupported architecture fails much later and far less clearly: the
    # containers exec-format-error on boot and the health wait below just runs
    # out after ten minutes. Ask docker rather than uname, so what we test is
    # the architecture the containers will actually run as.
    ARCH="$(docker version --format '{{.Server.Arch}}' 2>/dev/null || echo '')"
    [ -n "$ARCH" ] || ARCH="$(uname -m)"
    case "$ARCH" in
        amd64 | x86_64 | arm64 | aarch64) ;;
        *) die "unsupported architecture '${ARCH}'. b10cks images are published for amd64 and arm64 only." ;;
    esac

    say "architecture ${ARCH}"

    if command -v curl >/dev/null 2>&1; then
        fetch() { curl -fsSL "$1"; }
    elif command -v wget >/dev/null 2>&1; then
        fetch() { wget -qO- "$1"; }
    else
        die "neither curl nor wget is available."
    fi

    say "docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?')"

    step "Resolving the newest release"

    VERSION="${B10CKS_VERSION:-}"
    if [ -z "$VERSION" ]; then
        # Pin to a concrete tag rather than riding :latest, so a reinstall six
        # months from now reproduces the same stack.
        VERSION="$(fetch "https://api.github.com/repos/${REPO}/releases/latest" 2>/dev/null \
            | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
            | head -n1 || true)"
        # Refusing to install beats silently falling back to an unpinned branch:
        # that would break reproducibility and hand an on-path attacker who can
        # block one API call a downgrade-to-unreleased-code primitive.
        [ -n "$VERSION" ] || die "could not resolve the newest release from the GitHub API.
Retry later, or pin one explicitly:  curl -fsSL https://get.b10cks.com | B10CKS_VERSION=v2026.7.29 sh"
    fi

    case "$VERSION" in
        v[0-9]*) ;;
        *) die "B10CKS_VERSION must be a release tag like v2026.7.29 (got: $VERSION)" ;;
    esac

    REF="$VERSION"
    IMAGE_TAG="${VERSION#v}"
    say "$VERSION"

    step "Writing $DIR"

    if [ -e "$DIR/docker-compose.yml" ]; then
        die "$DIR already contains an installation. Remove it, or set B10CKS_DIR to a different path."
    fi

    mkdir -p "$DIR"
    fetch "${RAW}/${REF}/docker-compose.yml" > "$DIR/docker-compose.yml" \
        || die "could not download docker-compose.yml for ${REF}"
    fetch "${RAW}/${REF}/.env.docker.example" > "$DIR/.env" \
        || die "could not download .env.docker.example for ${REF}"
    chmod 600 "$DIR/.env"

    DB_PASSWORD="$(rand)"
    [ "${#DB_PASSWORD}" -ge 32 ] || die "could not generate a database password."

    # sed -i differs between GNU and BSD; write to a temp file and move instead.
    edit_env() {
        sed "$1" "$DIR/.env" > "$DIR/.env.tmp" && mv "$DIR/.env.tmp" "$DIR/.env"
        chmod 600 "$DIR/.env"
    }

    edit_env "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=${DB_PASSWORD}|"
    edit_env "s|^B10CKS_IMAGE_TAG=.*|B10CKS_IMAGE_TAG=${IMAGE_TAG}|"
    edit_env "s|^APP_PORT=.*|APP_PORT=${PORT}|"
    edit_env "s|^APP_URL=.*|APP_URL=http://localhost:${PORT}|"

    say "docker-compose.yml"
    say ".env (APP_KEY is generated inside the container on first boot)"

    step "Starting the stack"
    say "the first boot pulls images and runs migrations — this takes a few minutes"

    cd "$DIR"
    docker compose up -d || die "docker compose failed to start the stack. Inspect: cd $DIR && docker compose logs"

    step "Waiting for b10cks to become healthy"

    HEALTH="http://localhost:${PORT}/mgmt/v1/health"
    waited=0
    until curl -fsS "$HEALTH" >/dev/null 2>&1 || wget -q -O /dev/null "$HEALTH" 2>/dev/null; do
        waited=$((waited + 5))
        if [ "$waited" -gt 600 ]; then
            die "b10cks did not become healthy within 10 minutes. Inspect: cd $DIR && docker compose logs app"
        fi
        # A crash-looping app would otherwise be reported as a plain timeout after
        # ten minutes with no hint as to why.
        if [ "$(docker compose ps -q app | xargs -r docker inspect -f '{{.State.Restarting}}' 2>/dev/null)" = "true" ]; then
            die "the app container is restarting. Inspect: cd $DIR && docker compose logs app"
        fi
        sleep 5
    done

    printf '\n\033[32m✓\033[0m b10cks is running at \033[1mhttp://localhost:%s\033[0m\n\n' "$PORT"
    say "Open the URL and create the first account — it becomes the owner."
    say "Registration closes automatically once that account exists."
    say "Config:   $DIR/.env"
    say "Logs:     cd $DIR && docker compose logs -f app"
    say "Stop:     cd $DIR && docker compose down"
    printf '\n'
}

# Everything above only defines functions: a truncated download of this script
# executes nothing. This call has to stay the last line of the file.
main "$@"
