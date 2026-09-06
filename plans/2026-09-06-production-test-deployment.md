# Разделение production и test

> Заменён [полным восстановлением исходной выкатки](2026-09-06-restore-original-deployment.md).
> Ниже сохранена история, не действующая инструкция.

Цель: production backend в `/var/www/api.ablakin.ru` с существующей MySQL, test backend в `/var/code/ablaki` с существующей PostgreSQL. Сохраняются действующие .env и инфраструктура. По последнему решению владельца: обновить код и выполнить `make up` с миграциями, без backup. Production frontend уже работает из `/var/www/ablakin.ru`; test frontend публикуется в `/var/code/ablaki-front/project/dist`, сохраняя checkout.

## Группа A: Конфигурация и запуск
- [x] A1. Разделить workflow, секреты, URL и deploy-root production/test; production только master, test вручную с выбранной ветки.
- [x] A2. Проверять окружение приложения и принадлежность PHP/nginx нужному checkout. Прежняя обязательная проверка PostgreSQL container/volume убрана: production использует MySQL.
- [x] A3. Добавить независимые Compose-настройки и воспроизводимую PHP 7.3-сборку для нового production.

## Группа B: Первичная подготовка
- [x] B1. Устранить зависимость обычных миграций от необязательной старой remote_db.
- [x] B2. Проверить полный запуск миграций на отдельной PostgreSQL и новый PHP-образ: [CI 34029613007](https://github.com/fedornabilkin/ablaki/actions/runs/34029613007), также повторные миграции, backup/restore и nginx.
- [x] B3. Подготовить инструкции, env-образцы и nginx для api.ablakin.ru и тестового API.
- [x] B4. Согласовать источник production-данных: владелец подтвердил существующие production/test БД в соответствующих .env. Создание и копирование БД не требуется.
- [x] B5. Сверить конфиг БД: MYSQL_DB_HOST/MYSQL_DB_NAME выбирают MySQL, иначе PG_DB_* выбирают PostgreSQL. `make up` не запускает PostgreSQL при выбранной MySQL; миграции остаются включёнными.
- [x] B6. Владелец определил тест без доменов/TLS: API `http://94.250.251.94:3180/`, frontend `http://94.250.251.94:3181`, admin `http://94.250.251.94:3195`. Команды настройки сохранены в [инструкции](../docs/test-deployment-commands.md); применение на VPS отдельно.

## Группа C: Проверка и публикация
- [x] C1. Проверить итоговый сценарий без backup: 19 сценариев Bash, включая миграции для MySQL production и PostgreSQL test, ошибки миграций/health, выбор ветки и запрет dump/restore. Ранее прошли 12 health-проверок PHP 7.3 и actionlint обоих workflow.
- [x] C2. Зафиксировать и отправить master, проверить GitHub CI: checks и runtime успешны; владелец передал серверную ошибку dubious ownership для `/var/www/api.ablakin.ru`.
- [ ] C3. Применить серверную конфигурацию и выполнить первый production/test deploy при наличии SSH-доступа и сверенной конфигурации существующих БД.
- [x] C4. Устранить dubious ownership: все Git-вызовы деплоя и запись SHA в `make up` используют safe.directory только для выбранного checkout, без глобальной настройки. Ошибки серверного этапа выводятся в annotations GitHub.
- [x] C5. Поддержать HTTP test frontend, сохранив HTTPS production: 44 frontend deployment tests; независимый bind PostgreSQL при публичных test API/admin.
- [x] C6. Сохранить dev-зависимости только для test vendor: Composer dry-run на PHP 7.3.33 проверил 96 locked packages, включая Yii debug 2.0.14; 19 сценариев Bash учитывают platform requirements target.

Проверки используют только временные БД/контейнеры; сценарии Bash имитируют внешние команды, поэтому не подтверждают миграции на MySQL. Production-данные не копируются и не создаются автоматически. Без SSH серверный запуск остаётся отдельным шагом.
