FROM dunglas/frankenphp:php8.4-alpine

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
    && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/docker-php-override.ini
COPY docker/etc /etc

WORKDIR /app

COPY artisan /app/
COPY --chown=1000:1000 bootstrap /app/bootstrap/
COPY --chown=1000:1000 storage /app/storage/
COPY composer.json /app/
COPY vendor /app/vendor/
COPY public /app/public/
COPY resources /app/resources/
COPY config /app/config/
COPY database /app/database/
COPY routes /app/routes/
COPY app /app/app

VOLUME /app/storage

ARG APP_VERSION=latest
ENV APP_VERSION=$APP_VERSION

EXPOSE 8000 8001

ENTRYPOINT ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf", "-n"]
