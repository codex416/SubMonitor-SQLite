FROM php:8.2-fpm-alpine

RUN apk add --no-cache sqlite \
    && mkdir -p /opt/SubMonitor/data