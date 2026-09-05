# План: чат на бэкенде (поддержка WebSocket + REST для фронта)

## Контекст

Фронт (`frontend/docs/plan/chat.md`) реализует чат-клиент. На бэке его поддержки сейчас **нет**:

- В `common/modules/forum/` есть REST `v1/forum-theme` и `v1/forum-comment` (модели + UrlRule, см. `common/modules/forum/config/urlRules.php`), но это синхронный REST без real-time.
- В `docker-compose.yaml` сервис `redis` закомментирован, хотя yii redis-cache настроен (`common/config/main.php`).
- Никакого WebSocket-сервера в `back/yii2/` или `back/docker/` нет (vendor/* в счёт не идёт).

Цель — добавить модуль `common/modules/chat/` по образцу `common/modules/forum/` плюс отдельный сервис WebSocket-брокера, чтобы фронт мог подключиться по `VITE_WS_URL` из `frontend/docs/plan/chat.md` (б.2) и получать события `MESSAGE`/`TYPING`/`ROOM_JOINED` и т.д., описанные в `frontend/docs/plan/chat.md` (б.8).

## Решение по транспорту

Три реальных варианта; план написан для **варианта A** (по умолчанию рекомендуется). Варианты B/C — fallback, если A не подходит.

- **A. [Centrifugo](https://centrifugal.dev)** (рекомендация). Отдельный готовый docker-сервис WebSocket-брокера. Yii2 публикует события через его HTTP API; авторизация — JWT, который бэк выдаёт по REST. Минимум кастомного кода, нативный масштаб, presence/history/recovery из коробки.
- **B. PHP-демон на [Workerman](https://www.workerman.net) или [Ratchet](http://socketo.me)**. Один docker-сервис на PHP, держит соединения, читает из Postgres/Redis. Больше кода, медленнее, дольше деплой.
- **C. Отдельный Node-микросервис** (например, `ws` или `socket.io`) + Redis pub/sub из PHP. Новый стек в проекте, но самый гибкий.

Дальнейшие пункты описывают вариант **A**. Пункты `в.1`–`в.5` (БД и модели) одинаковы для всех вариантов и **не зависят** от выбора транспорта — их можно делать первыми.

## Принципы

- Каждый пункт `в.N` — самостоятельная единица работы. Группы выполняются в любом порядке внутри группы; между группами есть «требует» (миграции до моделей, модели до контроллеров, …).
- Никаких изменений в существующих модулях (`forum`, `exchange`, `games`) — всё новое идёт в `common/modules/chat/`.
- Соглашения проекта: PSR-4 пространство `common\modules\chat`, миграции через `make migration`, REST контроллеры в `api/controllers/` модуля, регистрация модуля и URL-правил через `Module::init()` + `App::urlManager()->addRules(...)` (см. как сделано в `common/modules/forum/Module.php` и `common/modules/forum/config/urlRules.php`).

## Группа 1: схема БД и миграции (можно делать первыми)

- [ ] **в.1** — Миграция `m_create_chat_room`: таблица `chat_room` (`id`, `name VARCHAR(250)`, `owner_id INT REFERENCES user(id)`, `is_private BOOL DEFAULT FALSE`, `password_hash VARCHAR(255) NULL`, `is_channel BOOL DEFAULT FALSE`, `last_post BIGINT NULL`, `created_at`, `updated_at`). Файл в `back/yii2/console/migrations/`.
- [ ] **в.2** — Миграция `m_create_chat_message`: таблица `chat_message` (`id BIGSERIAL`, `room_id INT REFERENCES chat_room(id) ON DELETE CASCADE`, `user_id INT REFERENCES user(id)`, `text TEXT NOT NULL`, `edited_at BIGINT NULL`, `deleted_at BIGINT NULL`, `created_at`). Индекс `(room_id, id DESC)` под пагинацию истории.
- [ ] **в.3** — Миграция `m_create_chat_room_member`: таблица `chat_room_member` (`room_id`, `user_id`, `role VARCHAR(16) DEFAULT 'member'` со значениями `owner|admin|member`, `joined_at`, `last_read_message_id BIGINT NULL`, PRIMARY KEY `(room_id, user_id)`). Под подсчёт `unread` и роли.
- [ ] **в.4** — Миграция `m_create_chat_reaction`: таблица `chat_reaction` (`message_id`, `user_id`, `emoji VARCHAR(8)`, `created_at`, PRIMARY KEY `(message_id, user_id, emoji)`). Множество эмодзи — на уровне валидации в коде (как в референсе: `👍 ❤️ 😂 😮 😢 👎`).

## Группа 2: модели и query (требует в.1–в.4)

- [ ] **в.5** — `common/modules/chat/models/ChatRoom.php` + `ChatRoomQuery.php`: ActiveRecord по образцу `common/modules/forum/models/ForumTheme.php`. Поведения: `TimestampBehavior` (created_at/updated_at), `BlameableBehavior` (owner_id). Связи: `getMessages()`, `getMembers()`, `getOwner()`.
- [ ] **в.6** — `common/modules/chat/models/ChatMessage.php` + `ChatMessageQuery.php`: AR + `TimestampBehavior` + `BlameableBehavior(createdByAttribute => 'user_id')`. Связи: `getRoom()`, `getUser()`, `getReactions()`. Метод `fields()` нормализует payload для REST/WS (id, room_id, user_id, text, created_at, edited_at, deleted_at, reactions[]).
- [ ] **в.7** — `common/modules/chat/models/ChatRoomMember.php`: AR с PK `(room_id, user_id)`. Метод `markRead($messageId)` обновляет `last_read_message_id`.
- [ ] **в.8** — `common/modules/chat/models/ChatReaction.php`: простая AR; конст-список разрешённых эмодзи в валидаторе.

## Группа 3: модуль и REST-эндпоинты (требует в.5–в.8)

- [ ] **в.9** — `common/modules/chat/Module.php`: класс модуля по образцу `common/modules/forum/Module.php` (i18n-сорсы из `messages/`, регистрация URL-правил). Регистрация модуля в `common/config/main.php` под ключом `chat` (рядом с `forum`, `exchange`, `games`) и в `api/config/main.php` `bootstrap`.
- [ ] **в.10** — `common/modules/chat/config/urlRules.php`: `UrlRule` для `v1/chat-room` и `v1/chat-message` (по образцу `forum/config/urlRules.php`). Эндпоинты: `GET v1/chat-room`, `POST v1/chat-room`, `GET v1/chat-room/{id}`, `POST v1/chat-room/{id}/join`, `POST v1/chat-room/{id}/leave`, `GET v1/chat-message?filter[room_id]=…&before=…&limit=50`, `POST v1/chat-message` (отправка, на случай offline), `PATCH v1/chat-message/{id}` (edit), `DELETE v1/chat-message/{id}`.
- [ ] **в.11** — `common/modules/chat/api/controllers/ChatRoomController.php`: `ActiveController` с экшенами index/view/create/delete + кастомные `actionJoin($id)`, `actionLeave($id)`. Filter по `is_private` + проверке `ChatRoomMember`.
- [ ] **в.12** — `common/modules/chat/api/controllers/ChatMessageController.php`: REST + `actionHistory` с `before`/`limit`, проверка членства в комнате через `ChatRoomMember`. Сообщения с `deleted_at != null` отдаются с пустым `text` и флагом `deleted=true`.
- [ ] **в.13** — Поиск `common/modules/chat/models/ChatRoomSearch.php`, `ChatMessageSearch.php` — по образцу `common/modules/forum/models/ThemeSearch.php` (filter, sort, пагинация).

## Группа 4: Centrifugo — WS-брокер и интеграция (вариант A)

- [ ] **в.14** — Добавить сервис `centrifugo` в `back/docker-compose.yaml`. Используется официальный образ `centrifugo/centrifugo:v5`, порт `8088`, конфиг через `centrifugo.json` (token_hmac_secret_key, allowed_origins, namespaces для `chat:` каналов, history_size=1000, history_ttl, presence=true, recover=true). Конфиг положить в `back/docker/centrifugo/centrifugo.json`. Раскомментировать сервис `redis` для presence/recovery Centrifugo и пробросить `REDIS_*` env.
- [ ] **в.15** — Сервис `common/modules/chat/service/CentrifugoPublisher.php`: HTTP-клиент на `yii\httpclient` к `http://centrifugo:8000/api`, методы `publish($channel, $payload)`, `presence($channel)`, `history($channel)`. Channel-схема: `chat:room#{room_id}` для комнаты, `chat:user#{user_id}` для приватных уведомлений.
- [ ] **в.16** — JWT-токен endpoint `POST v1/chat/ws-token`: контроллер `ChatAuthController` отдаёт подписанный HMAC-токен с `sub = user_id`, `channels = ['chat:user#'.user_id]`, `exp = now+30m`. Секрет общий с `centrifugo.json` через env `CENTRIFUGO_TOKEN_HMAC`. Фронт берёт токен через REST перед `socket.connect()`.
- [ ] **в.17** — Хук «после создания сообщения»: в `ChatMessage::afterSave()` (или в отдельном сервисе `ChatMessageService::create()`) при `insert === true` вызывать `CentrifugoPublisher::publish("chat:room#{$roomId}", ['type' => 'message', 'payload' => $model->toArray()])`. То же — для edit/delete/reaction.
- [ ] **в.18** — Endpoint subscribe-proxy (опционально, для приватных каналов): `POST v1/chat/subscribe-proxy` — Centrifugo дёргает его перед подпиской, бэк проверяет членство в `ChatRoomMember`. Альтернатива — выдавать subscription token в `actionJoin` и подписывать на фронте через `socket.newSubscription(channel, { token })`.

## Группа 5: сообщения и i18n

- [ ] **в.19** — Папка `common/modules/chat/messages/ru-RU/` с переводами (по образцу `forum/messages/`): `chat.php` со строками для attributeLabels и сервисных сообщений.

## Группа 6: тесты и smoke

- [ ] **в.20** — Codeception unit-тесты в `common/tests/unit/modules/chat/`: модель `ChatMessage::afterSave` дёргает publisher (мокаем `CentrifugoPublisher`); `ChatRoomMember::markRead` обновляет `last_read_message_id`; `ChatRoomController::actionJoin` отказывает не-членам для `is_private`.
- [ ] **в.21** — Запустить весь стек (`make init` → `make migration` после в.1–в.4 → `make up`), курлом проверить: `POST /v1/chat/ws-token` отдаёт JWT; `POST /v1/chat-room` создаёт комнату; через Centrifugo CLI `centrifugo gencli token` и подключение через `wscat` к `ws://localhost:8088/connection/websocket` с этим токеном принимает соединение; `POST /v1/chat-message` приводит к публикации события в `chat:room#1`, видимого подключённому клиенту.

## Дополнительно (если выбран не A)

- [ ] **в.22 (B)** — Если Centrifugo не подходит: PHP-демон на Workerman, файл `back/yii2/console/controllers/ChatWsController.php`, supervisor-конфиг, отдельный порт. Авторизация — JWT, выданный тем же `ChatAuthController`. Опускает в.14–в.18.
- [ ] **в.23 (C)** — Если выбран Node-сервис: новая папка `back/docker/chat-ws/` с `Dockerfile`, `server.mjs` (на `ws`/`socket.io`), подписанный на Redis pub/sub. Из PHP пишем в Redis `PUBLISH chat:room#{id} ...`. Заменяет в.15.

## Зависимости фронта от бэка

Фронт ожидает:
- `VITE_API_URL` (уже есть) и `VITE_WS_URL` (новая) — координируется с фронтом, см. `frontend/docs/plan/chat.md` (б.2).
- Структура payload событий совпадает с константами `IN`/`OUT` из `frontend/docs/plan/chat.md` (б.8). Если на бэке выбран Centrifugo, тип события кодируется в JSON-теле публикации `{ "type": "message", "payload": {...} }`, фронт диспатчит по `type`.
- REST-ответы — формат как у `forum`: на ошибки — `{errors: {...}}` в body, на успех — голый payload, заголовок `x-pagination-total-count` для списков.
