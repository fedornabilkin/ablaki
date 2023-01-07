#!/bin/bash
if [[ ${ENV} = "DEV" ]]; then
    echo "Installing xdebug..."
    pecl install xdebug
    echo "xdebug has been installed"

    echo "Add configurations xdebug to php.ini file..."
    printf "[xdebug]\nzend_extension=xdebug.so\nxdebug.remote_enable=1\nxdebug.remote_port=9000\nxdebug.remote_log='/tmp/xdebug_log.log'\nxdebug.remote_host=ablaki.local\nxdebug.profiler_enable=0\nxdebug.profiler_enable_trigger=1\nxdebug.profiler_output_dir='/web/backend/xdebug'\nxdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
    echo "xdebug configurations has been added"
fi
