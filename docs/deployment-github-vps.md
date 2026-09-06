# Деплой backend

Актуальная инструкция: [Production и тестовый деплой](production-test-deployment.md).

Production backend размещается в `/var/www/api.ablakin.ru` и использует существующую MySQL по `MYSQL_DB_*` своей `.env`. Test backend — `/var/code/ablaki`, существующая PostgreSQL по `PG_DB_*`. Служебные каталоги — `/opt/ablaki-backend` и `/opt/ablaki-backend-test` соответственно.

Текущий сценарий: обновить код и vendor, выполнить обычный `make up` с миграциями, проверить API. Backup/dump перед деплоем не выполняется. Действующие `.env`, базы, Compose project, сети и порты сохраняются; создание или копирование БД не требуется. `APP_ENVIRONMENT` задаётся как `production` или `test`; полный `/health` подтверждает готовность для frontend.
