# Деплой backend: исходный запуск восстановлен

Docker и запуск восстановлены из commit `c875c7c`, предшествующего первой
автоматизации `4640955`. Это полный возврат файлов запуска, а не перенос
прежней дополнительной логики внутрь make up.

## Что восстановлено

- Makefile, docker-compose.yaml и весь docker/, включая PHP Dockerfile, entrypoint и nginx.
- yii2/yii, yii2/console/migrations/AbstractMigration.php и yii2/composer.json.
- Удалены дополнительные start/migrate/compose/backup/health-скрипты, runtime-тесты
  нового механизма и примеры, предлагавшие переустройство серверной инфраструктуры.
- Удалены добавленные backend /health и требование его ответа для frontend deploy.

Прикладные функции игр, форума, списков и профилей сохраняются.

## Две команды

Production checkout был и остаётся /var/www/api.ablakin.ru:

```bash
cd /var/www/api.ablakin.ru
git pull --ff-only origin master
make up
```

SSH-скрипт делает то же самое, добавляя git -c safe.directory для этого checkout.
Он не переключает ветку и не сбрасывает локальные изменения.

Исходный make up выполняет:

```bash
docker-compose up --detach --remove-orphans
docker-compose ps
```

Compose запускает сервисы исходного файла. Инициализация Yii и пять команд миграций
выполняются прежним docker/php/entrypoint.sh при старте PHP-контейнера.
Отдельного обязательного запуска миграций перед Compose больше нет.

Старый entrypoint не прерывает запуск PHP-FPM при ошибке миграций. Поэтому зелёный
make up сам по себе не доказывает, что миграции успешно применились. Ошибки не
обходятся SQL-командами, таблицы не удаляются, история миграций не подменяется.

## Куда направлялись миграции

Удалённый deploy/migrate.sh запускал yii через PHP-сервис Compose из production
checkout. Этот сервис получал его .env. Подключение выбиралось тем же
yii2/common/config/main.php, что и до автоматизации:

- MYSQL_DB_HOST и MYSQL_DB_NAME заполнены — MySQL из MYSQL_DB_*.
- Иначе — PostgreSQL из PG_DB_*.
- common/config/main-local.php и console/config/main-local.php могут переопределить db.

APP_ENVIRONMENT, YII_ENV и имя Compose project сами по себе не выбирают базу.
По ошибке «таблица уже существует» нельзя определить фактический сервер и имя БД.
Возможное объяснение — таблицы есть, а ожидаемой записи в выбранной таблице
истории миграций нет. Подтверждать это нужно по реальному подключению и истории,
а не помечать миграции выполненными для обхода ошибки.

## Серверные настройки после отката

Git-откат не возвращает изменения, ранее внесённые в .env на VPS. В частности,
добавленное во время настройки COMPOSE_PROJECT_NAME=ablaki-production может выбрать
другой набор контейнеров и volumes вместо прежнего проекта.

Для проверки существующих контейнеров без изменения их состояния:

```bash
docker ps -a --format '{{.Names}} | {{.Label "com.docker.compose.project"}} | {{.Label "com.docker.compose.project.working_dir"}} | {{.Status}}'
```

Владелец прислал список контейнеров: прежний production project — `apiablakinru`.
Его `apiablakinru_php_1`, `apiablakinru_nginx_1` и `apiablakinru_postgres_1`
работают три недели. В production .env верните `COMPOSE_PROJECT_NAME=apiablakinru`
вместо добавленного `ablaki-production`. Тестовый проект — `ablaki`.
Верните только ранее изменённое значение по фактической старой конфигурации. Не назначайте
придуманное имя проекта, новую сеть или новую БД. Не выполняйте down --volumes,
network prune и не удаляйте занятую сеть.

Исходный Compose снова использует подсеть 192.168.22.0/24 и порты PORT_NGINX_*,
PG_DB_PORT. Введённые мной COMPOSE_SUBNET, APP_BIND_IP, PG_BIND_IP больше не участвуют
в восстановленном файле. Сохранённые файлы system nginx и Docker-ресурсы на VPS
не меняются автоматически при этом откате.

## GitHub Actions

Существующие настройки SSH сохраняются:

| Production | Test |
| --- | --- |
| BACKEND_DEPLOY_HOST | TEST_BACKEND_DEPLOY_HOST |
| BACKEND_DEPLOY_PORT | TEST_BACKEND_DEPLOY_PORT |
| BACKEND_DEPLOY_USER | TEST_BACKEND_DEPLOY_USER |
| BACKEND_DEPLOY_SSH_KEY | TEST_BACKEND_DEPLOY_SSH_KEY |
| BACKEND_DEPLOY_KNOWN_HOSTS | TEST_BACKEND_DEPLOY_KNOWN_HOSTS |

Environments: production-backend и test-backend. PORT — SSH-порт.
Скрипт хранится соответственно в /opt/ablaki-backend и /opt/ablaki-backend-test;
права ранее настроенного SSH-пользователя на checkout, /opt и Docker сохраняются.

Push master запускает production. Ручной test выбирает существующую ветку
тестового checkout /var/code/ablaki. Backend health variables, vendor artifacts,
releases и APP_ENVIRONMENT для этого деплоя не требуются.

Для публикации новой версии workflow нужен новый запуск из актуального master.
Повтор старого run использует старый workflow. Откат публикуется с `[skip ci]`,
чтобы сначала восстановить прежний Compose project на VPS. После этого выполните
обычные git pull и make up из production checkout. Следующие обычные push в master
снова запускают автодеплой; workflow для этого не отключается.

[Тестовый стенд](test-deployment-commands.md). Production frontend остаётся
в /var/www/ablakin.ru; его статика и страницы этим откатом не заменяются.
