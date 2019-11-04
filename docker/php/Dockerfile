FROM php:7.3.2-fpm

RUN apt-get update \
&& apt-get install -y libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
&& docker-php-ext-configure gd --with-gd --with-jpeg-dir=/usr/lib64 \
&& docker-php-ext-install pdo pdo_pgsql pdo_mysql intl gd \
&& docker-php-ext-enable gd

