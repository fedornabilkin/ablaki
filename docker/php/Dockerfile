FROM php:7.3.2-fpm

RUN apt-get update \
&& apt-get install -y libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
&& docker-php-ext-configure gd --with-gd --with-jpeg-dir=/usr/lib64 \
&& docker-php-ext-install pdo pdo_pgsql pdo_mysql intl gd \
&& docker-php-ext-enable gd

ARG ENV
COPY xdebugInstall.sh xdebugInstall.sh
RUN chmod u+x xdebugInstall.sh && ./xdebugInstall.sh

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod a+x /entrypoint.sh

CMD /entrypoint.sh
