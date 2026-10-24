FROM composer:2.10.3 AS composer
FROM php:8.3.36-fpm-bookworm

RUN apt-get update \
&& apt-get install -y --no-install-recommends libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev cron rsyslog git unzip ca-certificates \
&& apt-get clean \
&& apt-get autoclean \
&& apt-get autoremove -y \
&& rm -rf /var/lib/apt/lists/* \
&& docker-php-ext-configure gd --with-jpeg --with-freetype \
&& docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl gd zip opcache \
&& docker-php-ext-enable gd

#ARG ENV
#COPY xdebugInstall.sh xdebugInstall.sh
#RUN chmod u+x xdebugInstall.sh && ./xdebugInstall.sh

RUN export XDEBUG_INI=/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo '' >> $XDEBUG_INI \
    && echo 'xdebug.client_host=192.168.240.1' >> $XDEBUG_INI \
    && echo 'xdebug.mode=debug' >> $XDEBUG_INI \
    && echo 'xdebug.start_with_request=yes' >> $XDEBUG_INI \
    && echo 'xdebug.remote_connectback=off' >> $XDEBUG_INI \
    && echo 'xdebug.log_level=0' >> $XDEBUG_INI \
    && echo 'xdebug.client_port=9003' >> $XDEBUG_INI

COPY ./cron /var/spool/cron/crontabs/root
COPY ./entrypoint.sh /entrypoint.sh
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY ./runtime.ini /usr/local/etc/php/conf.d/zz-ablaki.ini
COPY ./security-fpm.conf /usr/local/etc/php-fpm.d/zz-ablaki-security.conf
WORKDIR /web/yii2

RUN chmod a+x /entrypoint.sh && \
    chmod 600 /var/spool/cron/crontabs/root

CMD ["/entrypoint.sh"]
