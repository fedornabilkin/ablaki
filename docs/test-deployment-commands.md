# Подготовка тестового деплоя на 94.250.251.94

API — `http://94.250.251.94:3180/`, frontend — `http://94.250.251.94:3181`, админка — `http://94.250.251.94:3195`. Backend обновляется в `/var/code/ablaki`, статика frontend — только в `/var/code/ablaki-front/project/dist`. Существующая PostgreSQL и её реквизиты сохраняются. Деплой выполняет `make up` с миграциями, без backup.

Ниже команды для VPS, под администратором с sudo. Используется уже настроенный SSH-пользователь `deploy`; если он другой, поменяйте первую переменную. Выполняйте блоки последовательно в одном Bash-сеансе. Эти команды подготовлены по коду workflow; на VPS из этой задачи они не выполнялись.

## 1. Права на файлы, GitHub и Docker

```bash
set -e
test_deploy_user=deploy
test_deploy_group=$(id -gn "$test_deploy_user")
test_backend=/var/code/ablaki
test_frontend=/var/code/ablaki-front/project/dist

test -d "$test_backend/.git"
test -f "$test_backend/.env"
test -d /var/code/ablaki-front/project
test "$(readlink -f "$test_backend")" = /var/code/ablaki
test "$(readlink -f /var/code/ablaki-front/project)" = /var/code/ablaki-front/project
if [ -e "$test_frontend" ]; then
  test "$(readlink -f "$test_frontend")" = /var/code/ablaki-front/project/dist
fi

sudo apt-get install -y acl rsync
sudo groupadd -f docker
sudo usermod -aG docker "$test_deploy_user"
sudo setfacl -R -P -m "u:${test_deploy_user}:rwX" "$test_backend"

sudo install -d -o "$test_deploy_user" -g "$test_deploy_group" -m 0700 \
  /opt/ablaki-backend-test /opt/ablaki-backend-test/incoming /opt/ablaki-backend-test/releases \
  /opt/ablaki-frontend-test /opt/ablaki-frontend-test/incoming /opt/ablaki-frontend-test/releases

sudo -u "$test_deploy_user" git -c safe.directory="$test_backend" \
  -C "$test_backend" remote set-url origin https://github.com/fedornabilkin/ablaki.git
sudo -u "$test_deploy_user" git -c safe.directory="$test_backend" \
  -C "$test_backend" fetch origin master
sudo -u "$test_deploy_user" git -c safe.directory="$test_backend" \
  -C "$test_backend" status --short
sudo -u "$test_deploy_user" -H docker ps
```

`fetch` и `docker ps` должны пройти без ошибки. Если `status --short` показывает изменённые отслеживаемые файлы, сохраните их перед запуском workflow: он не сбрасывает локальные изменения. Членство в группе docker даёт пользователю права управления Docker на уровне root; новый SSH-сеанс Actions получит эти права автоматически.

## 2. Настройки тестового backend

Откройте **существующий** env:

```bash
sudo -u "$test_deploy_user" nano /var/code/ablaki/.env
```

Установите или проверьте только следующие значения, без повторяющихся строк:

```dotenv
APP_ENVIRONMENT=test
APP_BIND_IP=0.0.0.0
PG_BIND_IP=127.0.0.1
PORT_NGINX_API=3180
PORT_NGINX_ADMIN=3195
```

В nano: Ctrl+O → Enter → Ctrl+X. Существующие `PG_DB_*`, Compose project, сеть и параметры debug сохраните. `MYSQL_DB_HOST`/`MYSQL_DB_NAME` из production сюда не переносить: заполненные значения переключают Yii на MySQL. `PG_BIND_IP` управляет только публикацией порта PostgreSQL, не адресом подключения приложения к БД.

`PORT_NGINX_FRONT` относится к старому PHP frontend внутри backend-контейнера. Сохраните его отдельный действующий порт: он **не должен быть 3181**, который выделен Vue SPA на системном nginx. Если там уже указан 3181, сначала определите, кто сейчас обслуживает этот порт, и перенесите ненужный PHP frontend на свободный порт.

Подготовьте служебные каталоги PHP, сохранив существующее содержимое:

```bash
for app in api backend frontend console; do
  runtime="$test_backend/yii2/$app/runtime"
  sudo mkdir -p "$runtime"
  sudo setfacl -R -P -m "u:${test_deploy_user}:rwX,u:33:rwX" "$runtime"
  sudo find "$runtime" -xdev -type d \
    -exec setfacl -m "d:u:${test_deploy_user}:rwx,d:u:33:rwx" {} +
  if [ "$app" != console ]; then
    assets="$test_backend/yii2/$app/web/assets"
    sudo mkdir -p "$assets"
    sudo setfacl -R -P -m "u:${test_deploy_user}:rwX,u:33:rwX" "$assets"
    sudo find "$assets" -xdev -type d \
      -exec setfacl -m "d:u:${test_deploy_user}:rwx,d:u:33:rwx" {} +
  fi
done
```

UID 33 — PHP-FPM www-data в текущем Dockerfile. Зависимости тестового backend включают Yii debug. Существующие настройки модуля и разрешённых IP сохраняются; сам адрес `/debug/default/index` не включает модуль. Не подменяйте локальные Yii-конфиги файлами production.

## 3. Каталог frontend и nginx на 3181

```bash
sudo install -d -o "$test_deploy_user" -g www-data -m 0755 "$test_frontend"
sudo setfacl -R -P -m "u:${test_deploy_user}:rwX,u:www-data:rX" "$test_frontend"
for parent in /var /var/code /var/code/ablaki-front /var/code/ablaki-front/project; do
  for reader in "$test_deploy_user" www-data; do
    if ! sudo -u "$reader" test -x "$parent"; then
      sudo setfacl -m "u:${reader}:--x" "$parent"
    fi
  done
done
sudo -u "$test_deploy_user" test -w "$test_frontend"
sudo -u www-data test -x "$test_frontend"

sudo nginx -T 2>/dev/null | grep -nE '^# configuration file|listen.*3181|root.*ablaki-front'
sudo ss -ltnp | grep -E ':(3180|3181|3195)[[:space:]]' || true
```

В **существующем** nginx server для 3181 установите `root /var/code/ablaki-front/project/dist;`. Полный готовый [конфиг frontend на 3181](https://github.com/fedornabilkin/ablaki-front/blob/master/deploy/nginx/test-frontend.http.conf.example) содержит SPA fallback, корректный `/assets/` и отсутствие кеширования index/version. Используйте его содержимое для этого server, не добавляя вторую копию того же сайта. Если 3181 занят не системным nginx, сначала определите действующий процесс по `ss`; второго слушателя на том же IP/порту быть не должно.

После правки существующего файла:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

API 3180 и admin 3195 публикует Docker nginx backend, frontend 3181 — системный nginx. Домены и сертификаты для этого тестового стенда не нужны. Если на VPS включён UFW, разрешите нужные HTTP-порты:

```bash
sudo ufw status
# Только если UFW активен и эти порты ещё не разрешены:
sudo ufw allow 3180/tcp
sudo ufw allow 3181/tcp
sudo ufw allow 3195/tcp
```

## 4. GitHub settings

Для этого первого запуска можно использовать того же `deploy` и уже настроенный SSH-ключ. В двух репозиториях заведите отдельные test-настройки со следующими именами. `PORT` ниже — SSH-порт VPS, а не 3180/3181/3195. Закрытый ключ берётся из исходного сохранённого файла; GitHub не показывает значение уже сохранённого secret.

| Backend secret | Frontend secret | Значение |
| --- | --- | --- |
| `TEST_BACKEND_DEPLOY_HOST` | `TEST_FRONTEND_DEPLOY_HOST` | `94.250.251.94` |
| `TEST_BACKEND_DEPLOY_PORT` | `TEST_FRONTEND_DEPLOY_PORT` | Уже используемый SSH-порт |
| `TEST_BACKEND_DEPLOY_USER` | `TEST_FRONTEND_DEPLOY_USER` | `deploy`, если не заменён выше |
| `TEST_BACKEND_DEPLOY_SSH_KEY` | `TEST_FRONTEND_DEPLOY_SSH_KEY` | Уже настроенный приватный SSH-ключ |
| `TEST_BACKEND_DEPLOY_KNOWN_HOSTS` | `TEST_FRONTEND_DEPLOY_KNOWN_HOSTS` | Проверенная запись ключа того же VPS |

Backend secrets можно разместить в environment `test-backend`, frontend — в `test-frontend`; также поддерживаются repository secrets с этими именами. При использовании уже настроенного пользователя с тем же ключом менять `authorized_keys` не требуется.

Repository variables:

| Репозиторий | Имя | Значение |
| --- | --- | --- |
| `ablaki` | `TEST_BACKEND_HEALTHCHECK_URL` | `http://94.250.251.94:3180/` |
| `ablaki-front` | `TEST_VITE_API_URL` | `http://94.250.251.94:3180/` |
| `ablaki-front` | `TEST_FRONTEND_HEALTHCHECK_URL` | `http://94.250.251.94:3181` |

`TEST_VITE_WS_URL` необязателен и заполняется только адресом действующего тестового WebSocket-сервиса. Production variables не менять.

## 5. Запуск

1. В `ablaki`: Actions → **Backend CI and deployment** → Run workflow → **Use workflow from: master**, **target: test**. Workflow сам установит код/vendor, затем выполнит `make up` с миграциями существующей PostgreSQL.
2. Дождитесь успеха backend и проверьте:

```bash
curl -fsS http://94.250.251.94:3180/health
curl -I http://94.250.251.94:3195/
```

В `/health` ожидаются `status: "ok"`, `environment: "test"`, SHA и `portalListsVersion: 1`. Админка может перенаправить на вход.

3. В `ablaki-front`: Actions → **Frontend CI and deploy** → Run workflow → **Use workflow from: master**, **target: test**, **branch: master**.
4. После успеха:

```bash
curl -fsS http://94.250.251.94:3181/deploy-version.txt
curl -I http://94.250.251.94:3181/forum
```

Frontend обращается к API на 3180. Серверный поиск/списки и readiness проверяют CORS для origin `http://94.250.251.94:3181`. Если уже выполняется другой backend-deploy на том же VPS, дождитесь его завершения перед первым тестовым запуском.
