# Production и тестовый деплой

Серверная автоматизация повторяет две команды владельца: `git pull` и `make up`.
CI проверяет код на GitHub; после этого SSH запускает обновление существующего checkout.
Установка vendor из Actions, предварительные Docker-контейнеры, остановка приложения,
пересборка образов и изменение .env из серверного deploy-скрипта удалены.

| Параметр | Production | Test |
| --- | --- | --- |
| Backend checkout | `/var/www/api.ablakin.ru` | `/var/code/ablaki` |
| Deploy-скрипт на VPS | `/opt/ablaki-backend/backend-deploy.sh` | `/opt/ablaki-backend-test/backend-deploy.sh` |
| Существующая БД | MySQL, `MYSQL_DB_*` | PostgreSQL, `PG_DB_*` |
| API | https://api.ablakin.ru/ | http://94.250.251.94:3180/ |
| Frontend | https://ablakin.ru | http://94.250.251.94:3181 |
| Админка | Действующая production-настройка | http://94.250.251.94:3195 |
| Запуск | Push master или вручную production из master | Вручную test из ветки, уже выбранной на VPS |

## Настройки GitHub и VPS

Существующие SSH secrets сохраняются:

| Production secret | Test secret |
| --- | --- |
| BACKEND_DEPLOY_HOST | TEST_BACKEND_DEPLOY_HOST |
| BACKEND_DEPLOY_PORT | TEST_BACKEND_DEPLOY_PORT |
| BACKEND_DEPLOY_USER | TEST_BACKEND_DEPLOY_USER |
| BACKEND_DEPLOY_SSH_KEY | TEST_BACKEND_DEPLOY_SSH_KEY |
| BACKEND_DEPLOY_KNOWN_HOSTS | TEST_BACKEND_DEPLOY_KNOWN_HOSTS |

Environments: `production-backend` и `test-backend`. PORT — SSH-порт.
`BACKEND_HEALTHCHECK_URL` и `TEST_BACKEND_HEALTHCHECK_URL` больше не нужны для
backend workflow; ранее заданные значения можно оставить.

SSH-пользователю нужны права записи в checkout, служебный каталог и доступ к Docker.
Служебный каталог используется только для скрипта. Если он уже подготовлен,
дополнительные команды не нужны. Для отсутствующих служебных каталогов:

```bash
backend_deploy_user=deploy
backend_deploy_group=$(id -gn "$backend_deploy_user")
sudo install -d -o "$backend_deploy_user" -g "$backend_deploy_group" -m 0700   /opt/ablaki-backend /opt/ablaki-backend-test
```

Репозиторий на VPS должен уже содержать действующие .env, vendor и локальные конфиги
Yii. Доступ к GitHub проверяется под тем же SSH-пользователем. Для публичного
репозитория можно использовать origin `https://github.com/fedornabilkin/ablaki.git`.
Автоматизация не создаёт checkout, не заменяет vendor и не меняет подключения БД.
[Подготовка тестового стенда и его портов](test-deployment-commands.md).

## Что выполняется при публикации

В production после перехода в checkout выполняется:

```bash
cd /var/www/api.ablakin.ru
git -c safe.directory=/var/www/api.ablakin.ru pull --ff-only origin master
make up
```

В test меняется путь на `/var/code/ablaki`. Ветка Actions должна совпадать с веткой
этого checkout. Скрипт не переключает ветки и не сбрасывает локальные изменения.
`--ff-only` останавливает обновление при расходящейся истории; после ошибки pull
`make up` не выполняется. Git trust ограничен выбранным checkout и одной командой.

Состав `make up`:

1. Применить пять существующих наборов миграций к БД, выбранной конфигом Yii, и очистить schema cache.
2. Записать SHA для существующего frontend health-контракта.
3. Запустить PHP/nginx через Compose без принудительного пересоздания и вывести их состояние.

Базы уже должны работать. Определение драйвера отдельным контейнером и автоматический
запуск PostgreSQL убраны. Выбор MySQL/PostgreSQL остаётся в приложении: заполненные
MYSQL_DB_HOST и MYSQL_DB_NAME выбирают MySQL, иначе используются PG_DB_*.
Миграции сохранены; их ошибка передаётся в Actions и останавливает дальнейший запуск.

Полный возврат старого Makefile/entrypoint не применяется: он запускал бы Composer 1.8
и PostgreSQL в обоих окружениях, а старый entrypoint делал Yii init Development.
Текущий `make up` использует существующий vendor. Если зависимости действительно
меняются, их обновление нужно выполнить отдельно, как часть такой конкретной задачи.
Исправленный PHP 7.3.33 Dockerfile сохранён; deploy-скрипт не пересобирает образ.

## Исправление Pool overlaps

В общей сети Compose убран фиксированный IPAM subnet. Ранее значение по умолчанию
192.168.22.0/24 могло повторно назначаться новому production project и конфликтовать
с уже занятой сетью. Новая сеть получает подсеть от Docker; настройка
COMPOSE_SUBNET больше не используется этим compose-файлом.

Изменение применяется после pull. Удалять сети, выполнять network prune, down
или менять COMPOSE_PROJECT_NAME для этого исправления не нужно. Существующие
настройки портов, имена проекта и volumes в репозитории не переименованы.
Если на VPS есть локальный compose override с собственным ipam, он имеет приоритет:
при повторе ошибки проверьте этот конкретный override, не удаляйте чужие сети.

[Документация Docker: сети Compose](https://docs.docker.com/reference/compose-file/networks/).

## Запуск и проверка результата

Используйте **новый** запуск Backend CI and deployment из актуального master.
Повтор старого run использует старую версию workflow. Push master запускает production;
вручную выберите Run workflow → Use workflow from: master → target: production.
Для test выберите target: test и ветку существующего тестового checkout.

Успех backend job означает успешное завершение pull и make up. Полная HTTP-проверка
больше не блокирует этот backend job. После запуска можно проверить production API:

```bash
curl -fsS https://api.ablakin.ru/health
```

Frontend сохраняет свою проверку /health, окружения, совместимости списков и CORS.
Для неё APP_ENVIRONMENT в существующей .env должен быть production или test.
Серверный deploy-скрипт больше не дописывает эту переменную автоматически.

Frontend раздаётся из /var/www/ablakin.ru в production и
/var/code/ablaki-front/project/dist в test. Backend checkout не раздавать как статику.
Готовые nginx-примеры в deploy/nginx относятся к первоначальной настройке;
работающие vhost и порты не нужно заменять ради автодеплоя.

Артефакты старого процесса в /opt не используются и автоматически не удаляются.
Backup/dump, перенос данных и восстановление БД не входят в текущий деплой.
