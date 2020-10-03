FROM php:7.3.2-fpm

RUN apt-get update \
&& apt-get install -y libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
&& docker-php-ext-configure gd --with-gd --with-jpeg-dir=/usr/lib64 \
&& docker-php-ext-install pdo pdo_pgsql pdo_mysql intl gd \
&& docker-php-ext-enable gd

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod a+x /entrypoint.sh

#COPY ./cron /var/spool/cron/crontabs/root
#RUN chmod 600 /var/spool/cron/crontabs/root
#
#RUN rm -f /etc/localtime && ln -sf /usr/share/zoneinfo/Europe/Moscow /etc/localtime \
#
#&& echo '[PHP]\ndate.timezone = "Europe/Moscow"\n' > /usr/local/etc/php/conf.d/tzone.ini
#
#ARG ENV
#COPY devinstall.sh devinstall.sh
#RUN chmod u+x devinstall.sh && ./devinstall.sh

CMD /entrypoint.sh
