# Деплой backend

Актуальная инструкция: [Production и тестовый деплой](production-test-deployment.md).

Production backend размещается в `/var/www/api.ablakin.ru` и использует существующую MySQL по `MYSQL_DB_*` своей `.env`. Test backend — `/var/code/ablaki`, существующая PostgreSQL по `PG_DB_*`. Служебные каталоги — `/opt/ablaki-backend` и `/opt/ablaki-backend-test` соответственно.

Текущий сценарий: `git pull --ff-only origin master`, затем `make up` с миграциями. Загрузка vendor из Actions, предварительные Docker-проверки, изменение `.env`, остановка приложения, rebuild и backup исключены из серверного deploy-скрипта. Существующие БД должны уже работать. Для новой сети Compose убрана фиксированная подсеть; `COMPOSE_SUBNET` больше не используется. `APP_ENVIRONMENT` задаётся при настройке как `production` или `test`; полный `/health` отдельно подтверждает готовность для frontend.
