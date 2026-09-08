FROM php:8.2-fpm-alpine

RUN apk add --no-cache sqlite \
    && mkdir -p /opt/SubMonitor/data \
    && chown -R www-data:www-data /opt/SubMonitor/data \
    && chmod 775 /opt/SubMonitor/data

WORKDIR /opt/SubMonitor
