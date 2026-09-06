# Production и тестовый деплой

Уточнённая схема от 6 сентября 2026 года:

| Параметр | Production | Test |
| --- | --- | --- |
| Frontend | `/var/www/ablakin.ru` | `/var/code/ablaki-front/project/dist` |
| Backend checkout | `/var/www/api.ablakin.ru` | `/var/code/ablaki` |
| Frontend releases | `/opt/ablaki-frontend` | `/opt/ablaki-frontend-test` |
| Backend archives, releases, backups | `/opt/ablaki-backend` | `/opt/ablaki-backend-test` |
| Сайт | `https://ablakin.ru` | `https://test.ablakin.ru` — пример |
| API | `https://api.ablakin.ru/` | `https://api-test.ablakin.ru/` — пример |
| Compose project | `ablaki-production` | Существующее имя, обычно `ablaki` |
| API на localhost | `127.0.0.1:19881` | `127.0.0.1:19882` |
| Сеть Docker | `192.168.23.0/24` | Существующая, обычно `192.168.22.0/24` |
| Запуск | Push master; вручную production на master | Вручную test с выбранной ветки |

Тестовые домены и новые порты пока являются примерами. Исходники тестового frontend сохраняются: nginx раздаёт только `project/dist`. Backend checkout находится в каталоге с именем домена, но системный nginx **проксирует запросы в контейнер**, а не раздаёт checkout и `.env` как статику.

## 1. Изменения в GitHub

В [настройках backend-репозитория](https://github.com/fedornabilkin/ablaki/settings/secrets/actions):

| Production secret | Test secret | Значение |
| --- | --- | --- |
| `BACKEND_DEPLOY_HOST` | `TEST_BACKEND_DEPLOY_HOST` | IP/DNS VPS |
| `BACKEND_DEPLOY_PORT` | `TEST_BACKEND_DEPLOY_PORT` | SSH-порт |
| `BACKEND_DEPLOY_USER` | `TEST_BACKEND_DEPLOY_USER` | Пользователь с правами на каталоги и Docker |
| `BACKEND_DEPLOY_SSH_KEY` | `TEST_BACKEND_DEPLOY_SSH_KEY` | Приватный SSH-ключ |
| `BACKEND_DEPLOY_KNOWN_HOSTS` | `TEST_BACKEND_DEPLOY_KNOWN_HOSTS` | Проверенная запись SSH host key |

**Production `BACKEND_HEALTHCHECK_URL` изменить на `https://api.ablakin.ru/`.** Test variable `TEST_BACKEND_HEALTHCHECK_URL` задаёт отдельный адрес тестового API, также с `/` на конце. Тест не подставляет production-секреты при отсутствии своих настроек. Настройки можно хранить в environments `production-backend`, `test-backend` или как repository secrets/variables с указанными именами. Ключи не публикуются в Git или чате.

**Во frontend production `VITE_API_URL` также изменить на `https://api.ablakin.ru/`**, затем пересобрать frontend. Для теста используются `TEST_VITE_API_URL`, `TEST_FRONTEND_HEALTHCHECK_URL` и пять `TEST_FRONTEND_DEPLOY_*` secrets в `test-frontend`. Полная frontend-инструкция — `docs/deployment-github-vps.md` репозитория `ablaki-front`.

## 2. Каталоги VPS

Пример рассчитан на действующий Debian/Ubuntu VPS с Docker Compose и nginx. Подставьте фактического SSH-пользователя вместо `deploy`:

```bash
backend_deploy_user=deploy
backend_deploy_group=$(id -gn "$backend_deploy_user")
sudo install -d -o "$backend_deploy_user" -g "$backend_deploy_group" -m 0755 /var/www/api.ablakin.ru
sudo install -d -o "$backend_deploy_user" -g "$backend_deploy_group" -m 0700 \
  /opt/ablaki-backend /opt/ablaki-backend/incoming /opt/ablaki-backend/releases /opt/ablaki-backend/backups \
  /opt/ablaki-backend-test /opt/ablaki-backend-test/incoming /opt/ablaki-backend-test/releases /opt/ablaki-backend-test/backups
```

Только в **пустой** production-каталог клонируйте backend. Если файлы уже есть, сначала проверьте и сохраните их:

```bash
sudo -u "$backend_deploy_user" git clone https://github.com/fedornabilkin/ablaki.git /var/www/api.ablakin.ru
```

Тестовый `/var/code/ablaki` не переносить. Проверьте оба checkout под SSH-пользователем: `git status --short`, ветку, origin и неинтерактивный `git fetch origin master`. Production использует master. Тест может переключаться на выбранную ветку GitHub; workflow сохраняет другие локальные ветки и не сбрасывает расходящиеся коммиты или локальные правки.

## 3. Отдельные Docker-проекты и .env

В новом production скопируйте [production.env.example](../deploy/env/production.env.example) в `/var/www/api.ablakin.ru/.env`, замените `REPLACE_ME` отдельным паролем и установите права `0600`. Production обязательно использует `APP_ENVIRONMENT=production`, `COMPOSE_PROJECT_NAME=ablaki-production`, собственные БД, сеть и порты. `PG_DB_HOST=postgres` разрешается внутри собственного Docker-проекта.

В существующей тестовой `.env` внесите изменения по [test.env.example](../deploy/env/test.env.example), **не заменяя файл целиком**. Сохраните фактическое имя Compose-проекта, имя БД, пользователя, пароль и сеть: иначе можно выбрать новый пустой volume. Добавьте `APP_ENVIRONMENT=test`. Текущие project/cwd читаются из labels PostgreSQL-контейнера:

```bash
cd /var/code/ablaki
bash deploy/compose.sh ps -q postgres
# Подставить полученный container ID:
docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' CONTAINER_ID
docker inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' CONTAINER_ID
```

В образцах API порты `19881/19882`, серверный UI `18091/18092`, admin `18081/18082`, PostgreSQL `15431/15432`. Проверьте свободные порты и непересекающиеся сети перед применением. Они привязаны к `127.0.0.1`; публичные запросы идут через системный nginx. Если тестовый API ранее использовал внешний IP:порт, сначала подготовьте его домен/proxy и обновите потребителей.

Для нового production под SSH-пользователем:

```bash
cd /var/www/api.ablakin.ru
bash deploy/compose.sh config --quiet
bash deploy/compose.sh build php
bash deploy/compose.sh up -d postgres
```

Это запускает только отдельную production-БД. До запуска API подготовьте данные. `make init` и `down --volumes` не использовать на действующих окружениях. Новый PHP-образ сохраняет совместимость PHP 7.3.33; переход на поддерживаемую ветку PHP — отдельная задача.

## 4. Первые production-данные

Источник нужно выбрать явно: копия текущей тестовой БД, существующая отдельная production-БД или пустая новая база. Workflow не копирует и не восстанавливает данные между окружениями автоматически.

При переносе создайте PostgreSQL custom dump **исходной БД**, проверьте восстановление в отдельную временную БД, затем восстановите в новую production-БД. Согласуйте окно переключения, если в исходной системе продолжаются начисления, переводы или игры. Не подключайте production к тестовому volume. Сохраните dump вне VPS; используемые пользовательские загрузки перенесите отдельно от checkout/vendor с нужными путями и правами.

Для первичной подготовки зависимостей используйте artifact `backend-<SHA>` из Actions: скачайте, распакуйте и передайте на VPS `vendor.tar.gz` с `.sha256`. Сверьте checksum и `commit.txt` внутри архива с `git rev-parse HEAD`; устанавливайте vendor в новый подготовленный checkout. После распаковки в `yii2/vendor` проверьте реальные расширения PHP:

```bash
cd /var/www/api.ablakin.ru
bash deploy/compose.sh run --rm --no-deps -T --workdir /web/yii2 --entrypoint php php \
  vendor/bin/deploy-composer.phar check-platform-reqs --no-dev
```

Создайте runtime и assets с правами PHP-FPM и SSH-пользователя. В стандартном PHP-образе www-data имеет UID 33; `setfacl` предоставляется пакетом `acl`. Для нового checkout:

```bash
cd /var/www/api.ablakin.ru
for app in api backend frontend; do
  mkdir -p "yii2/$app/runtime" "yii2/$app/web/assets"
  setfacl -R -m u:33:rwx,u:"$(id -u)":rwx "yii2/$app/runtime" "yii2/$app/web/assets"
  setfacl -d -m u:33:rwx,u:"$(id -u)":rwx "yii2/$app/runtime" "yii2/$app/web/assets"
done
mkdir -p yii2/console/runtime
setfacl -R -m u:33:rwx,u:"$(id -u)":rwx yii2/console/runtime
setfacl -d -m u:33:rwx,u:"$(id -u)":rwx yii2/console/runtime
bash deploy/migrate.sh
```

Обычным миграциям больше не требуется старый `params['remote_db']`. Локальные настройки Yii не перезаписываются. При пустой базе **создайте и проверьте владельца до публичного открытия API**: историческая RBAC-миграция назначает admin пользователю с ID 1. Не оставляйте первый аккаунт открытой регистрации. Для копии БД проверьте ожидаемого владельца/admin и балансы. Точный первичный запуск зависит от выбранного источника данных.

## 5. nginx и HTTPS API

Добавьте DNS A для `api.ablakin.ru` на VPS и выберите отдельный тестовый API-домен. `api-test.ablakin.ru` в примерах заменяется вместе с путями сертификатов. AAAA нужна только при работающем IPv6.

Сначала используйте [HTTP-конфиг](../deploy/nginx/api.ablakin.ru.http.conf.example), отдающий только ACME challenge, и получите сертификат. Если конфиг или symlink уже существуют, проверьте текущие файлы и сохраните копию перед заменой:

```bash
sudo mkdir -p /var/www/letsencrypt
sudo cp /var/www/api.ablakin.ru/deploy/nginx/api.ablakin.ru.http.conf.example /etc/nginx/sites-available/api.ablakin.ru
sudo ln -s /etc/nginx/sites-available/api.ablakin.ru /etc/nginx/sites-enabled/api.ablakin.ru
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d api.ablakin.ru
```

После подготовки данных и сертификата установите [HTTPS-конфиг](../deploy/nginx/api.ablakin.ru.conf.example):

```bash
sudo cp /var/www/api.ablakin.ru/deploy/nginx/api.ablakin.ru.conf.example /etc/nginx/sites-available/api.ablakin.ru
sudo nginx -t
sudo systemctl reload nginx
```

Для теста повторите с `api-test.ablakin.ru*.conf.example` и портом `19882`. Не добавляйте `default_server`: frontend уже использует этот nginx. Backend URI передаётся без добавления `/api/`. CORS обрабатывается контейнерным nginx; дублировать эти заголовки на внешнем proxy не нужно.

## 6. Запуск и проверка

Backend Actions: **Backend CI and deployment → Run workflow → target=production, branch=master**. Для теста — `target=test` и выбранная ветка. Push master запускает только production. У окружений независимые секреты, блокировки и каталоги релизов.

CI проверяет PHP/API, конфигурации Compose, сборку PHP-образа, полный запуск миграций и восстановление dump в временной PostgreSQL. На VPS до изменения приложения проверяются checkout, `APP_ENVIRONMENT`, принадлежность PostgreSQL-контейнера и его volume, SHA/checksum. Production требует отдельный `ablaki-production`, а test не может использовать production-проект или API-хост.

На время backup и замены vendor/кода API и cron останавливаются. `make up` применяет миграции до запуска PHP/nginx. В конце внутренний API и внешний `health` должны подтвердить SHA и `environment: "production"` либо `"test"`. Только тогда обновляется `current`.

Затем запустите frontend workflow **для того же окружения**. Он собирает bundle с нужным API URL, проверяет готовность и environment API. При отказе прежняя статика сохраняется. Backend push сам по себе не запускает отдельный frontend-репозиторий.

## 7. Ошибки и восстановление

До изменения checkout/vendor ошибка возобновляет прежние PHP/nginx. После изменения кода или миграций API остаётся остановленным до исправления. Автоматического отката БД нет.

В соответствующем `/opt/ablaki-backend` или `/opt/ablaki-backend-test` хранятся `backups/*.dump` и checksum, `releases/<sha>.<suffix>/previous-sha`, `previous-branch`, `previous-vendor`; `current` — SHA успешного релиза. Старые копии не удаляются автоматически. Изучайте логи именно нужного Docker-проекта. Повтор после исправления применяет оставшиеся миграции; восстановление данных согласуется отдельно с учётом выполненных операций.

## Статус

Код workflow и образцы конфигурации не означают, что DNS, сертификаты, данные и каталоги уже настроены на VPS. Для первого серверного запуска нужны SSH-доступ, выбранный источник production-данных и тестовые домены. Автоматические проверки используют только временные базы и контейнеры.
