# Облакин — backend

PHP 8.3.36 / Yii 2.0.55: JSON API, админка, серверный UI и консольные команды. Приложения и Composer находятся в `yii2/`; общие домены — `yii2/common/modules/`, сервисы — `yii2/common/services/`.

[Документация проекта](docs/README.md) · [Коллекция API](docs/operations/insomnia.md)

## Окружение и запуск

Поддерживаются MySQL/MariaDB и PostgreSQL. По подтверждению владельца production использует MySQL/MariaDB; локальный `docker-compose.yaml` содержит PostgreSQL. Настройки задаются по `.env-sample`; секреты не публикуются.

Из корня `back/`: `make up` или `docker-compose up -d`. `make init` включает удаление volumes, поэтому не использовать его для обычного запуска существующей БД. Порядок миграций чистой установки — в [yii2/README.md](yii2/README.md). Новый мир включается отдельно от установки схемы, по [плану запуска](docs/plan/2026-10-21-mvp-launch-readiness.md).

## Проверки

После установки зависимостей из `yii2/composer.lock`, из `yii2/`:

```sh
php tests/release/world-economy-profile.php
php -d extension=pdo_sqlite tests/release/auth-response.php
php -d extension=pdo_sqlite tests/release/world-economy-gameplay.php
```

Нужны расширения PHP, включая mbstring и pdo_sqlite. Сценарии используют автономный расчёт или изолированные fixtures; production-БД для тестов запрещена. Расширенный CI проверяет SQLite/MySQL/PostgreSQL, включая конкуренцию; локальный SQLite не заменяет проверки целевых СУБД.

`make codecept` — отдельный набор common/frontend/backend. API-регрессии текущего CI запускаются release-скриптами; `api` не включён в корневой `yii2/codeception.yml`.

## Состояние на 30 октября 2026 года

Локальный MVP автоматически проверен. Следующий этап — ручной маршрут двух участников, затем отдельная production-подготовка. Денежное продолжение после исчерпания заказов/NPC-средств остаётся в [BAL:B3/B4](docs/plan/2026-10-15-world-economy-balance.md). [Очередь работ](docs/plan/README.md) и [правила разработки](agreements/general.md).
