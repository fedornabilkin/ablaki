# Восстановление исходной выкатки

## Цель и контекст

По прямому требованию владельца полностью вернуть Docker и запуск приложения
к состоянию до автоматизации — `c875c7c`, родитель первого deploy-коммита `4640955`.
Production и прежде запускался из `/var/www/api.ablakin.ru`.
Оставить SSH-автоматизацию `git pull` и `make up`, без изменения смысла make up.
Предыдущий частичный откат d971f9e не удовлетворял этой просьбе.

## Группа A: Восстановление

- [x] A1. Восстановить Makefile, docker-compose.yaml и весь docker/ из c875c7c.
- [x] A2. Восстановить yii2/yii, AbstractMigration и Composer manifest из c875c7c.
- [x] A3. Удалить добавленные runtime/migration/backup/health-скрипты и примеры инфраструктуры.
- [x] A4. Удалить зависимость frontend deploy от добавленного backend /health; 44 проверки deploy и actionlint прошли.
- [x] A5. Сверить прежний Compose project по списку контейнеров владельца: production apiablakinru (PHP/nginx/PostgreSQL Up 3 weeks), test ablaki. Нужно вернуть в production .env COMPOSE_PROJECT_NAME=apiablakinru.

## Группа B: Проверки и публикация

- [x] B1. Подтвердить отсутствие diff с c875c7c для всех восстановленных файлов, включая весь docker/.
- [x] B2. Проверить Bash, PHP 7.3 lint и 7 сценариев SSH-wrapper без доступа к БД; actionlint прошёл. Все 6 прикладных API-наборов на временной SQLite прошли; реальные данные VPS не использовались.
- [x] B3. Обновить соглашение и инструкции с точным описанием старого запуска.
- [x] B4. Подготовить публикацию отката с [skip ci], чтобы сначала вернуть прежний project в .env на VPS. Обычная автоматизация push master остаётся включённой.
- [ ] B5. Подтвердить production запуск после восстановления серверного project; сами контейнеры до отката работали 3 недели.

## Критерии готовности

Docker и runtime совпадают с исходным commit, make up снова выполняет только
docker-compose up --detach --remove-orphans и docker-compose ps. Инициализация
и миграции остаются в прежнем entrypoint. Реальное здоровье API подтверждается
отдельно от exit code make up: старый entrypoint не останавливался при ошибках
миграций. Подключение БД не переопределяется автоматизацией.
