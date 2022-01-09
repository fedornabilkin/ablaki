FROM php:7.3.2-fpm

RUN apt-get update \
&& apt-get install -y libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
&& docker-php-ext-configure gd --with-gd --with-jpeg-dir=/usr/lib64 \
&& docker-php-ext-install pdo pdo_pgsql pdo_mysql intl gd \
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


COPY ./entrypoint.sh /entrypoint.sh
RUN chmod a+x /entrypoint.sh

CMD /entrypoint.sh

#FROM php:7.3-fpm
#
#RUN apt-get update && \
#    apt-get install -y libpq-dev zlib1g-dev libzip-dev zip && \
#    docker-php-ext-install zip pdo pdo_pgsql && \
#    apt-get clean && \
#    apt-get autoclean && \
#    apt-get autoremove -y --force-yes && \
#    rm -rf /var/lib/apt/lists/*
#
#RUN export XDEBUG_INI=/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
#    && echo '' >> $XDEBUG_INI \
#    && echo 'xdebug.client_host=192.168.240.1' >> $XDEBUG_INI \
#    && echo 'xdebug.mode=debug' >> $XDEBUG_INI \
#    && echo 'xdebug.start_with_request=yes' >> $XDEBUG_INI \
#    && echo 'xdebug.remote_connectback=off' >> $XDEBUG_INI \
#    && echo 'xdebug.log_level=0' >> $XDEBUG_INI \
#    && echo 'xdebug.client_port=9003' >> $XDEBUG_INI

