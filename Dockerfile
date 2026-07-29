FROM dunglas/frankenphp:php8.5-alpine

# Everything in this image runs as this unprivileged user: supervisord itself and
# therefore octane/frankenphp, the queue workers, the scheduler and reverb.
ARG APP_UID=1000
ARG APP_GID=1000

RUN apk add --no-cache supervisor ffmpeg libheif vips mariadb-client \
    && install-php-extensions \
    bcmath \
    exif \
    ffi \
    gd \
    intl \
    mysqli \
    pdo_mysql \
    pcntl \
    redis \
    zlib \
    && addgroup -g ${APP_GID} app \
    && adduser -D -u ${APP_UID} -G app -h /home/app -s /sbin/nologin app \
    # Octane serves on 8000 (reverb on 8001), so the binary never needs to bind a
    # privileged port — drop the file capability the base image ships with.
    && apk add --no-cache --virtual .setcap libcap \
    && { setcap -r /usr/local/bin/frankenphp || true; } \
    && apk del .setcap \
    # An extension installer left in the image is a ready-made download-and-compile
    # primitive for anyone who lands a shell in it.
    && rm -f /usr/local/bin/install-php-extensions \
    && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/docker-php-override.ini
COPY docker/etc /etc

WORKDIR /app

# Application code stays root-owned and read-only to the runtime user: a
# compromised worker cannot rewrite the PHP that the next request executes.
COPY artisan /app/
COPY bootstrap /app/bootstrap/
COPY storage /app/storage/
COPY composer.json /app/
COPY vendor /app/vendor/
COPY public /app/public/
COPY resources /app/resources/
COPY config /app/config/
COPY database /app/database/
COPY routes /app/routes/
COPY app /app/app

# storage/ and bootstrap/cache are the only writable paths in /app; /config and
# /data are frankenphp's XDG dirs, where caddy keeps its state.
COPY docker/entrypoint.sh /usr/local/bin/b10cks-entrypoint

RUN mkdir -p \
        /app/storage/app/private \
        /app/storage/app/public \
        /app/storage/app/setup \
        /app/storage/app/spaces \
        /app/storage/app/transfers \
        /app/storage/framework/cache/data \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/storage/tmp \
        /app/bootstrap/cache \
        /config/caddy /data/caddy \
    # public/ is gitignored and root-owned here, so octane can no longer drop the
    # worker stub in on first boot — bake it in at build time instead.
    && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php \
    && chmod -R go-w /app \
    && chmod 755 /usr/local/bin/b10cks-entrypoint \
    && chown -R app:app /app/storage /app/bootstrap/cache /config /data /home/app

VOLUME /app/storage

ARG APP_VERSION=latest
ENV APP_VERSION=$APP_VERSION
ENV HOME=/home/app
# ffmpeg/mysqldump and friends honour TMPDIR, not php.ini's sys_temp_dir
ENV TMPDIR=/app/storage/tmp

EXPOSE 8000 8001

USER app

# Pass-through to supervisord on SaaS; on self-hosted installs it also
# provides a persisted APP_KEY and runs one-shot auto-setup (see the script).
ENTRYPOINT ["/usr/local/bin/b10cks-entrypoint"]
