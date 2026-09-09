#!/bin/bash

service cron start && \
service rsyslog start && \

php /web/yii2/init --env=Development --overwrite=n

# The source tree is mounted from the host, so runtime contents can retain
# ownership and modes from a previous container. Yii only updates the
# runtime directory itself; normalize nested paths on every container start
# so PHP-FPM can write caches, logs, locks and generated assets as well.
for runtime in /web/yii2/api/runtime /web/yii2/backend/runtime /web/yii2/frontend/runtime; do
    mkdir -p "$runtime"
    find "$runtime" -type d -exec chmod 0777 {} +
    find "$runtime" -type f -exec chmod 0666 {} +
done

php /web/yii2/yii migrate/up --migrationPath=@vendor/dektrium/yii2-user/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@fedornabilkin/binds/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@fedornabilkin/redirect/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@yii/rbac/migrations --interactive=0
php /web/yii2/yii migrate --interactive=0

php-fpm
