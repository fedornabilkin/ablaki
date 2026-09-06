# Разделение production и test

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
- [ ] B6. Указать реальные тестовые домены в DNS/nginx/GitHub.

## Группа C: Проверка и публикация
- [x] C1. Проверить итоговый сценарий без backup: 19 сценариев Bash, включая миграции для MySQL production и PostgreSQL test, ошибки миграций/health, выбор ветки и запрет dump/restore. Ранее прошли 12 health-проверок PHP 7.3 и actionlint обоих workflow.
- [x] C2. Зафиксировать и отправить master, проверить GitHub CI: checks и runtime успешны; серверный шаг передачи/деплоя завершился с ошибкой без подробного сообщения.
- [ ] C3. Применить серверную конфигурацию и выполнить первый production/test deploy при наличии SSH-доступа и сверенной конфигурации существующих БД.

Проверки используют только временные БД/контейнеры; сценарии Bash имитируют внешние команды, поэтому не подтверждают миграции на MySQL. Production-данные не копируются и не создаются автоматически. Без SSH серверный запуск остаётся отдельным шагом.
