FROM php:8.2-fpm-alpine

RUN apk add --no-cache sqlite sqlite-dev \
    && docker-php-ext-install -j2 sqlite3 \
    && mkdir -p /opt/SubMonitor/data \
    && chown -R www-data:www-data /opt/SubMonitor/data \
    && chmod 775 /opt/SubMonitor/data

WORKDIR /opt/SubMonitor
