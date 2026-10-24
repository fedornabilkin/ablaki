#!/bin/bash

set -eu
cd /web/yii2/
# Preserve the installation policy established by deployment (test/dev vs production).
export COMPOSER_ALLOW_SUPERUSER=1
set -- --no-interaction --prefer-dist --no-progress --optimize-autoloader
if [ -f vendor/composer/installed.json ] && php -r '$m=json_decode(file_get_contents("vendor/composer/installed.json"),true); exit(($m["dev"] ?? true) ? 1 : 0);'; then
    set -- "$@" --no-dev
fi
exec composer install "$@"
