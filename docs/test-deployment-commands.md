# Существующий тестовый стенд

Этот документ заменяет прежнюю инструкцию, которая предлагала менять инфраструктуру
для автоматизации. Docker и Makefile восстановлены; сначала владелец запускает
production прежним способом, улучшения тестового деплоя отложены до отдельной просьбы.

| Сервис | Адрес |
| --- | --- |
| API | http://94.250.251.94:3180/ |
| Frontend | http://94.250.251.94:3181 |
| Админка | http://94.250.251.94:3195 |
| Существующий debug API | http://94.250.251.94:3180/debug/default/index |

Backend checkout: /var/code/ablaki, его существующая PostgreSQL настроена в .env.
Frontend web-root: /var/code/ablaki-front; системный nginx раздаёт готовую сборку прямо из этого каталога. Прежний frontend checkout сохраняется за пределами web-root; команды перехода находятся в [инструкции frontend](https://github.com/fedornabilkin/ablaki-front/blob/master/docs/deployment-github-vps.md#3-подготовить-только-test-каталоги-и-доступ).

Исходный Compose использует PORT_NGINX_API, PORT_NGINX_ADMIN, PORT_NGINX_FRONT и
PG_DB_PORT. Сохраните их действующие значения и прежние настройки сети/проекта.
Порт 3181 относится к Vue SPA на системном nginx, а не к PHP frontend в Compose.
APP_BIND_IP, PG_BIND_IP, COMPOSE_SUBNET из прежней инструкции больше не используются
в восстановленном compose-файле. Не копируйте новые примеры поверх существующей .env.

Для обычного обновления тестового backend, когда его настройки подтверждены:

```bash
cd /var/code/ablaki
git pull --ff-only origin master
make up
```

Workflow поддерживает ручной target=test и пять TEST_BACKEND_DEPLOY_* SSH secrets
из [общей инструкции](production-test-deployment.md). Ветка Actions должна совпадать
с уже выбранной веткой checkout. Backend /health и health variables больше не нужны.

Существующие TEST_VITE_API_URL=http://94.250.251.94:3180/ и
TEST_FRONTEND_HEALTHCHECK_URL=http://94.250.251.94:3181 остаются настройками frontend.
Frontend сохраняет публикацию статики и проверку своей версии, но не требует
добавленного backend /health. Новый nginx-конфиг и новые Docker-ресурсы для обычного
git pull и make up не создаются.
