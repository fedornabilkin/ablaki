# Production и тестовый деплой

Production использует существующую **MySQL** из `MYSQL_DB_*` в своей `.env`, test — существующую **PostgreSQL** из `PG_DB_*` в своей `.env`. Текущий деплой обновляет код и зависимости, затем выполняет обычный `make up` **с миграциями**. Backup/dump перед публикацией не создаётся. Создание новой БД, перенос данных между окружениями и смена инфраструктуры в этот процесс не входят.

| Параметр | Production | Test |
| --- | --- | --- |
| Frontend web-root | `/var/www/ablakin.ru` | `/var/code/ablaki-front/project/dist` |
| Backend checkout | `/var/www/api.ablakin.ru` | `/var/code/ablaki` |
| Frontend releases | `/opt/ablaki-frontend` | `/opt/ablaki-frontend-test` |
| Backend incoming, releases, состояние | `/opt/ablaki-backend` | `/opt/ablaki-backend-test` |
| База данных | Существующая MySQL, `MYSQL_DB_*` | Существующая PostgreSQL, `PG_DB_*` |
| `APP_ENVIRONMENT` | `production` | `test` |
| Сайт | `https://ablakin.ru` | `https://test.ablakin.ru` — пример |
| API | `https://api.ablakin.ru/` | `https://api-test.ablakin.ru/` — пример |
| Запуск | Push master; вручную production из master | Вручную test из выбранной ветки |

Тестовые домены ещё нужно выбрать и настроить. Compose project, Docker-сети, порты, имена сервисов и доступ к существующим БД сохраняются. `COMPOSE_PROJECT_NAME` не является обязательной новой настройкой: если он задан, сохраните значение; если используется существующее имя по умолчанию, не вводите другое ради примера.

## 1. Настройки GitHub

В [настройках backend-репозитория](https://github.com/fedornabilkin/ablaki/settings/secrets/actions) используйте отдельные параметры окружений:

| Production secret | Test secret | Значение |
| --- | --- | --- |
| `BACKEND_DEPLOY_HOST` | `TEST_BACKEND_DEPLOY_HOST` | IP/DNS VPS |
| `BACKEND_DEPLOY_PORT` | `TEST_BACKEND_DEPLOY_PORT` | SSH-порт |
| `BACKEND_DEPLOY_USER` | `TEST_BACKEND_DEPLOY_USER` | Пользователь с доступом к checkout, служебному каталогу и Docker |
| `BACKEND_DEPLOY_SSH_KEY` | `TEST_BACKEND_DEPLOY_SSH_KEY` | Приватный SSH-ключ |
| `BACKEND_DEPLOY_KNOWN_HOSTS` | `TEST_BACKEND_DEPLOY_KNOWN_HOSTS` | Проверенная запись SSH host key |

Production variable `BACKEND_HEALTHCHECK_URL` — `https://api.ablakin.ru/`. Test variable `TEST_BACKEND_HEALTHCHECK_URL` — отдельный адрес тестового API с `/` на конце. Настройки размещаются в environments `production-backend`, `test-backend` или как repository secrets/variables с указанными именами. Отсутствующие test-настройки не заменяются production-значениями. Ключи и содержимое `.env` не публикуются в Git или чате.

Во frontend production repository variable `VITE_API_URL` — `https://api.ablakin.ru/`. После изменения URL frontend нужно пересобрать. Для теста используются `TEST_VITE_API_URL`, `TEST_FRONTEND_HEALTHCHECK_URL` и пять `TEST_FRONTEND_DEPLOY_*` secrets в `test-frontend`. [Инструкция frontend](https://github.com/fedornabilkin/ablaki-front/blob/master/docs/deployment-github-vps.md).

## 2. Каталоги VPS

Для уже подготовленных каталогов проверьте доступ SSH-пользователя. Следующие команды нужны только для отсутствующих каталогов; вместо `deploy` подставьте фактического пользователя:

```bash
backend_deploy_user=deploy
backend_deploy_group=$(id -gn "$backend_deploy_user")
sudo install -d -o "$backend_deploy_user" -g "$backend_deploy_group" -m 0755 /var/www/api.ablakin.ru
sudo install -d -o "$backend_deploy_user" -g "$backend_deploy_group" -m 0700 \
  /opt/ablaki-backend /opt/ablaki-backend/incoming /opt/ablaki-backend/releases \
  /opt/ablaki-backend-test /opt/ablaki-backend-test/incoming /opt/ablaki-backend-test/releases
```

Только в **пустой** production-каталог клонируйте backend. Если checkout уже существует, сохраните его `.env`, локальные настройки Yii, пользовательские загрузки и изменения файлов:

```bash
sudo -u "$backend_deploy_user" git clone https://github.com/fedornabilkin/ablaki.git /var/www/api.ablakin.ru
```

Тестовый `/var/code/ablaki` не переносить. В каждом checkout под SSH-пользователем проверьте `git status --short`, ветку, origin и неинтерактивный `git fetch origin master`. Production использует master. Тест использует выбранную ветку; локальные правки и расходящиеся коммиты нужно сохранить до публикации. Каталог `/var/code/ablaki-front` является checkout frontend: nginx раздаёт только `project/dist`, исходники не заменяются архивом статики.

## 3. Существующие .env и PHP

Образцы [production.env.example](../deploy/env/production.env.example) и [test.env.example](../deploy/env/test.env.example) служат памяткой. **Не копируйте их поверх действующей `.env`.** Если `APP_ENVIRONMENT` отсутствует, деплой дописывает это поле для выбранного окружения; существующее значение и остальные строки сохраняются. Права `.env` — `0600`. Сохраните действующие параметры подключения:

| Production `.env` | Test `.env` |
| --- | --- |
| `MYSQL_DB_HOST`, `MYSQL_DB_NAME` | `PG_DB_HOST`, `PG_DB_NAME` |
| `MYSQL_DB_USER`, `MYSQL_DB_PASSWORD` | `PG_DB_USER`, `PG_DB_PASSWORD` |

В текущей конфигурации Yii заполненные `MYSQL_DB_HOST` и `MYSQL_DB_NAME` выбирают MySQL; иначе используется PostgreSQL. Поэтому не добавляйте production `MYSQL_DB_*` в test. Локальные переопределения Yii сохраняются вместе с `.env`.

Параметры сети, публикации портов, Compose project и существующих сервисов остаются фактическими настройками VPS. Не создавайте для этого релиза новые БД или volumes и не меняйте реквизиты подключения. `make init` и `down --volumes` для обновления действующего приложения не использовать.

Если production PHP-образ ещё не подготовлен, соберите только его из production checkout:

```bash
cd /var/www/api.ablakin.ru
bash deploy/compose.sh config --quiet
bash deploy/compose.sh build php
```

Команда `config --quiet` проверяет конфигурацию без вывода секретов. PHP-образ этого этапа сохраняет совместимость с PHP 7.3.33. Обновление версии PHP и инфраструктуры планируется отдельно.

## 4. Зависимости и первый запуск

Этот раздел нужен для нового PHP checkout. В уже работающем окружении обычный workflow сам устанавливает проверенный vendor перед `make up`.

Используйте artifact `backend-<SHA>` из Actions: скачайте, распакуйте и передайте на VPS `vendor.tar.gz` и `vendor.tar.gz.sha256`. Сверьте checksum архива и `commit.txt` внутри него с `git rev-parse HEAD`; устанавливайте vendor в подготовленный checkout той же ревизии. После распаковки в `yii2/vendor` проверьте реальные расширения PHP:

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
make up
```

`make up` применяет ожидающие миграции к существующей БД этого окружения и запускает приложение. Миграции не отключаются; отдельный запуск `deploy/migrate.sh` перед этой командой не нужен. Никакого предварительного dump или переноса test → production в данном сценарии нет. Для test используйте `/var/code/ablaki` и его существующую `.env`.

## 5. nginx и HTTPS API

Backend checkout находится в каталоге с именем домена, но системный nginx **проксирует API в контейнер**, а не раздаёт checkout и `.env` как статику. Сверьте `proxy_pass` с фактическим адресом и портом действующего API. Порты `19881/19882` в приложенных nginx-конфигах — примеры; исправьте upstream в примере под VPS, не перенастраивайте действующие порты ради документации.

Для нового API-домена направьте DNS A на VPS и выберите отдельный тестовый API-домен. `api-test.ablakin.ru` в примерах заменяется вместе с путями сертификатов. AAAA нужна только при работающем IPv6. Существующий рабочий vhost сохраняется, если адреса уже настроены.

При первичной настройке сначала используйте [HTTP-конфиг](../deploy/nginx/api.ablakin.ru.http.conf.example), отдающий только ACME challenge, и получите сертификат. Если конфиг или symlink уже существуют, проверьте их перед заменой:

```bash
sudo mkdir -p /var/www/letsencrypt
sudo cp /var/www/api.ablakin.ru/deploy/nginx/api.ablakin.ru.http.conf.example /etc/nginx/sites-available/api.ablakin.ru
sudo ln -s /etc/nginx/sites-available/api.ablakin.ru /etc/nginx/sites-enabled/api.ablakin.ru
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d api.ablakin.ru
```

После запуска PHP/API и получения сертификата установите [HTTPS-конфиг](../deploy/nginx/api.ablakin.ru.conf.example), предварительно исправив в нём upstream под фактический API:

```bash
sudo cp /var/www/api.ablakin.ru/deploy/nginx/api.ablakin.ru.conf.example /etc/nginx/sites-available/api.ablakin.ru
sudo nginx -t
sudo systemctl reload nginx
```

Для теста есть отдельные [HTTP](../deploy/nginx/api-test.ablakin.ru.http.conf.example) и [HTTPS](../deploy/nginx/api-test.ablakin.ru.conf.example) образцы. Не добавляйте `default_server`: frontend уже использует этот nginx. Backend URI передаётся без добавления `/api/`. CORS обрабатывается контейнерным nginx; дублировать эти заголовки на внешнем proxy не нужно.

## 6. Публикация и проверка

В backend Actions откройте **Backend CI and deployment → Run workflow**. Для production выберите **Use workflow from: master**, `target=production`. Для test выберите нужную существующую ветку в **Use workflow from**, `target=test`. Push master запускает production автоматически. У окружений независимые секреты, блокировки и каталоги релизов.

CI проверяет код и зависимости до передачи на VPS. Тесты с собственной временной БД не используют production или test данные VPS. Серверный сценарий соответствует привычному обновлению checkout и `make up`, с привязкой к проверенному SHA и vendor из Actions:

1. Проверяет checkout, SHA и checksum артефакта, сохраняет существующие `.env` и локальные настройки.
2. Обновляет код без принудительного сброса локальных изменений и устанавливает vendor. На время замены приложение останавливается.
3. Выполняет `make up`: миграции существующей БД, затем запуск PHP/nginx.
4. Проверяет внутренний и внешний API, опубликованный SHA и `environment` нужного окружения; после успеха обновляет `current`.

Backup/dump, SQL fingerprint, создание БД и перенос данных не являются этапами публикации. Значение `APP_ENVIRONMENT` должно соответствовать target и ответу `/health`.

Затем выпустите frontend **того же окружения**. Он ждёт полный `/health`, совместимость списков, правильный `environment` и CORS. Если backend ещё публикуется или схема/контракт не готовы, новая статика не активируется; прежний frontend остаётся. Backend push сам по себе не запускает отдельный frontend-репозиторий.

## 7. Ошибки и повтор

При ошибке изучите логи выбранного окружения и причину отказа `make up` или health. Миграция с ошибкой должна остановить запуск; не отмечайте её выполненной вручную для обхода ошибки. После исправления повторите публикацию: Yii применит оставшиеся миграции.

`/opt/ablaki-backend` и `/opt/ablaki-backend-test` содержат входящие артефакты, каталоги релизов и `current` успешной публикации. Предыдущие SHA/vendor позволяют разбирать сбой кода; они не являются копией БД. Автоматического восстановления данных нет. Уже существующие архивы или резервные копии не удаляются этим изменением процесса.

Наличие workflow и образцов не подтверждает настройки DNS, сертификатов, каталогов или успешную серверную публикацию. Проверки на VPS выполняются отдельно; существующие MySQL production и PostgreSQL test сохраняются.
