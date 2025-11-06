FROM dunglas/frankenphp:php8.4-alpine

RUN apk add --no-cache supervisor ffmpeg libheif vips \
   && install-php-extensions \
    bcmath \
    exif \
    ffi \
    intl \
    mysqli \
    pdo_mysql \
    pcntl \
    zlib \
  && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/docker-php-override.ini
COPY docker/etc /etc

WORKDIR /app

COPY artisan /app/
COPY --chown=1000:1000 bootstrap /app/
COPY --chown=1000:1000 storage /app/
COPY composer.json /app/
COPY vendor /app/
COPY public /app/
COPY resources /app/
COPY config /app/
COPY database /app/
COPY routes /app/
COPY app /app/

VOLUME /app/storage

ARG APP_VERSION=latest
ENV APP_VERSION=$APP_VERSION

EXPOSE 8000 8001

ENTRYPOINT ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf", "-n"]
