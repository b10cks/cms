FROM dunglas/frankenphp:php8.4-alpine

RUN apk add supervisor ffmpeg libheif vips
RUN install-php-extensions \
    bcmath \
    exif \
    ffi \
    intl \
    mysqli \
    pdo_mysql \
    pcntl \
    zlib

COPY docker/php.ini /usr/local/etc/php/conf.d/docker-php-override.ini
COPY docker/etc /etc

COPY artisan /app/
COPY composer.json /app/
COPY vendor /app/vendor
COPY storage /app/storage
RUN chown -R 1000:1000 /app/storage
COPY bootstrap /app/bootstrap
RUN chown -R 1000:1000 /app/bootstrap/cache
COPY resources /app/resources
COPY config /app/config
COPY database /app/database
COPY routes /app/routes
COPY app /app/app
COPY public /app/public

WORKDIR /app
VOLUME /app/storage

ARG APP_VERSION=latest
ENV APP_VERSION=$APP_VERSION

EXPOSE 8000
EXPOSE 8001

ENTRYPOINT ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf", "-n"]
