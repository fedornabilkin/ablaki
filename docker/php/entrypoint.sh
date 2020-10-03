service cron start && \
service rsyslog start

php /web/yii2/init --env=Development

php /web/yii2/yii migrate --interactive=0

php-fpm
