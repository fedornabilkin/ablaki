FROM php:7.3.33-fpm-buster

# Buster is archived. Keep repository signature verification enabled; only the
# expiration check is disabled for these frozen repositories.
# https://www.debian.org/distrib/archive
RUN set -eux; \
    printf '%s\n' \
        'deb [check-valid-until=no] https://archive.debian.org/debian buster main' \
        'deb [check-valid-until=no] https://archive.debian.org/debian-security buster/updates main' \
        > /etc/apt/sources.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        cron libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl gd zip opcache; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'if (PHP_VERSION_ID !== 70333) exit(1); foreach (["PDO", "pdo_pgsql", "pdo_mysql", "pdo_sqlite", "mbstring", "intl", "gd", "zip", "curl", "openssl", "json", "iconv", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: " . $extension . PHP_EOL); exit(1); } }'

COPY ./cron /var/spool/cron/crontabs/root
COPY ./entrypoint.sh /entrypoint.sh

RUN chmod a+x /entrypoint.sh && \
    chmod 600 /var/spool/cron/crontabs/root

ENTRYPOINT ["/bin/bash", "/entrypoint.sh"]
CMD ["php-fpm"]
