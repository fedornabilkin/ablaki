#!/bin/bash

service cron start && \
service rsyslog start && \

php /web/yii2/init --env=Development --overwrite=n

php /web/yii2/yii migrate/up --migrationPath=@vendor/dektrium/yii2-user/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@fedornabilkin/binds/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@fedornabilkin/redirect/migrations --interactive=0
php /web/yii2/yii migrate --migrationPath=@yii/rbac/migrations --interactive=0
php /web/yii2/yii migrate --interactive=0

# The source tree is mounted from the host, and CLI migrations can create
# runtime subdirectories as root (for example HTMLPurifier's HTML cache).
# Normalize all runtime contents after migrations and before PHP-FPM starts.
for runtime in /web/yii2/api/runtime /web/yii2/backend/runtime /web/yii2/frontend/runtime; do
    mkdir -p "$runtime"
    find "$runtime" -type d -exec chmod 0777 {} +
    find "$runtime" -type f -exec chmod 0666 {} +
done

php-fpm
