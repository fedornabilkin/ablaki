FROM composer:2.10.3 AS composer
FROM php:8.3.36-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /web/yii2

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod a+x /entrypoint.sh
