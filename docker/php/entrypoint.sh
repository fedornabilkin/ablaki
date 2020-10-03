#!/bin/bash

service cron start && \
service rsyslog start

php /web/yii2/init --env=Development

php yii migrate/up --migrationPath=@vendor/dektrium/yii2-user/migrations --interactive=0
php yii migrate --migrationPath=@fedornabilkin/binds/migrations --interactive=0
php yii migrate --migrationPath=@fedornabilkin/redirect/migrations --interactive=0
php yii migrate --migrationPath=@yii/rbac/migrations --interactive=0
php yii migrate --interactive=0

php-fpm
