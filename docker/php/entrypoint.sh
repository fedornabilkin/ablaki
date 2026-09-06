#!/usr/bin/env bash
set -Eeuo pipefail
cd /web/yii2
test -f vendor/autoload.php
# Migrations belong to make up/deploy/migrate.sh, before FPM and cron start.
# Preserve local configuration: a container restart must not run Yii init.
if [[ "$#" = 0 ]]; then set -- php-fpm; fi
if [[ "$1" = php-fpm ]]; then
  service cron start
  if [[ -x /etc/init.d/rsyslog ]]; then service rsyslog start; fi
fi
exec "$@"
