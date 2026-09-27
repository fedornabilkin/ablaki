# Контракты мира и интеграции крафта

Собственная финансовая ветвь: [OpenAPI](api/world-finance-hierarchy.openapi.json), [состав и консолидация](world-finance-hierarchy-implementation.md). GET `nodes/{id}/finance-hierarchy` по текущим финансовым связям, только доступные узлы владельца; внутренние переводы не увеличивают внешние обороты. Сверка каждого счёта, агрегаты и список состава с q/page. Лимит 200 узлов/32 уровня, без частичных итогов. Сервер/API без фронтенда, новой миграции и запуска проверок.

Финансовый отчёт объекта: [OpenAPI](api/world-finance-report.openapi.json), [границы сверки и суммы оборотов](world-finance-report-implementation.md). GET `nodes/{id}/finance-report`: owner-only остатки, сводка за всё время и отдельный фильтруемый журнал. Lifetime totals не зависят от фильтра; внутренние переводы не обозначаются новой выручкой. Новых миграций нет; код без тестов/выкладки, marker ветки 21.

Поступления казны: [OpenAPI](api/world-treasury-receipts.openapi.json), [прогноз и ограничения](world-treasury-receipts-implementation.md). GET `nodes/{id}/treasury-receipts` для владельца: поиск, состояния, страницы по 20 записей, сохранённые условия защиты, суммы сбора/потерь и прогноз одного необработанного периода. Чтение не меняет Cr и не запускает задания. Новых миграций нет; ветка требует marker 21, код не опубликован и не проверен.

Снос пустой купленной постройки: [OpenAPI](api/world-demolition.openapi.json), [границы и препятствия](world-demolition-implementation.md). Owner-only состояние, preview/command и история площадки; подтверждение без возврата, атомарное освобождение площади. Строительный каталог дополняется `demolished`. Требуется новая миграция 21, не применена; код не опубликован и не проверен. Последующие отметки схемы относятся к своим прежним этапам.

Договоры для прежних покупок: [OpenAPI](api/world-repair-contracts.openapi.json), [настройка и ограничения](world-repair-contracts-implementation.md). Совпадение исходного поселения/типа/площади, явное бесплатное принятие владельцем и неизменный снимок. Подготовлена миграция 20, не применена; код не опубликован и не проверен.

Ремонт зданий: [OpenAPI](api/world-building-repair.openapi.json), [настройка и границы](world-building-repair-implementation.md). Необязательный договор в новых предложениях/покупках, пропорциональные Cr/сырьё, бюджет здания → казна поселения. Schema marker 19 без миграций; код не опубликован и не проверен.

Работа готовой постройки: [OpenAPI](api/world-building-operation.openapi.json), [границы реализации](world-building-operation-implementation.md). Владелец приостанавливает/возобновляет здание через preview/command; пауза закрывает текущий ночлег с сохранением истории. Схема 19 без миграций; код не опубликован, проверки отложены.

Прочность оборудования: [OpenAPI](api/world-equipment-wear.openapi.json), [границы реализации](world-equipment-wear-implementation.md). Owner-only GET экземпляра возвращает текущую проекцию и историю по 20 записей с поиском/фильтром; не погашает износ. Фоновые задания используют существующий EquipmentExposure. Schema marker 18; блок пока не выложен.

Жильё: [OpenAPI](api/world-housing.openapi.json), [границы реализации](world-housing-implementation.md). Дом покупается через premises, включает одну койку отдельно от оборудования. Назначение/отмена в комнате связаны с общей занятостью персонажа в шалаше и историческим расчётом ночей; schema marker 17, код без приёмки/выкладки.

Места оборудования: [OpenAPI](api/world-equipment-expansion.openapi.json), [границы реализации](world-equipment-expansion-implementation.md). Расширяемые навесы/мастерские задают включённые места, предел и цену в предложении; оплата последующих мест идёт из бюджета комнаты с необязательным явным пополнением.

Огород и грядки: [OpenAPI](api/world-garden.openapi.json), [границы реализации](world-garden-implementation.md). Покупка из бюджета стоянки, открытия из бюджета огорода; явный top_up объединяет личный взнос и оплату. Посев/урожай ещё не включены.

Ночи и здоровье: [OpenAPI](api/world-nights.openapi.json), [правила и границы реализации](world-nights-implementation.md). GET не рассчитывает ночи; календарь публикуется отдельно, обработка выполняется worker.

Реализованный контракт шалаша: [OpenAPI](api/world-shelter.openapi.json), [границы блока](world-shelter-implementation.md), [ремонт](world-shelter-repair-implementation.md). Разовая выдача, установка/складывание, назначения ночлега и ручной ремонт за сырьё без Cr. `shelter-repair-preview` возвращает расход, доступность и прочность; `shelter-repair` сохраняет экземпляр и ночлег. night_resolution_enabled отражает наличие опубликованного календаря. Сейчас он ещё не включён.

Дата: 2026-09-26. Статус: проект для реализации. Связан с [архитектурой](world-architecture.md), [backend](plan/2026-09-26-world-backend.md), [БД](plan/2026-09-26-world-database.md) и [frontend](../../frontend/docs/plan/2026-09-26-world-frontend.md).

Уточнённые правила: [шалаш, ночлег, улица, бюджеты и расширения](world-gameplay-economy.md). Сбор всегда treasury→budget одного subject; отчисление budget→treasury родителя — отдельная команда. Treasury loss не запускается GET и не заменяет ручной сбор.

## Совместимость и общие правила

База существующего API `/v1`, текущий Bearer и auth session сохраняются. `/v1/craft`, `/v1/craft/command`, `/v1/craft/history` остаются совместимыми. Их state описывает рюкзак и переносные сундуки; размещённые вещи не подмешиваются в него, иначе существующий парсер и подсчёт доступных материалов станут неверны. Команды, принятые до обновления, повторяются с прежним fingerprint.

Для нового workspace используется `contract_version: 2`. Версия относится к DTO workspace, а не ко всем старым endpoints. Все timestamps — Unix seconds UTC; gather по-прежнему календарный Europe/Moscow. Денежные суммы нового API — decimal strings с currency `Cr`; quantities — ограниченные целые. Числовые ID ограничены безопасным диапазоном JS; будущий переход к bigint требует string DTO и отдельной версии, без тихого округления.

GET только читает. Списки используют `envelope=1`, `q`, filters, page/per-page и `{items,_meta}` как существующий `ApiList`; максимум страницы ограничен сервером. Дети дерева по умолчанию сортируются `(position,id)`, история `-id`. Для тяжёлой карты загружается viewport, а не дерево целиком. Личные хранилища/операции фильтруются до counts и aggregates.

## Чтение

| Метод и путь | Ответ и права |
|---|---|
| `GET /v1/world` | Активный мир, регионы первой страницы, server_time, capabilities/flags. |
| `GET /v1/world/nodes/{id}` | Общий node DTO + типизированные details; public summary отделён от owner details. |
| `GET /v1/world/nodes/{id}/children` | Пагинируемые видимые дочерние узлы. |
| `GET /v1/world/nodes/{id}/navigation` | Крошки, разрешённый parent, соседние объекты первой страницы, child counts; приватные названия не раскрываются. |
| `GET /v1/world/nodes/{id}/map` | Координаты текущего уровня, bbox/zoom, revision; без вложенного полного inventory. |
| `GET /v1/world/nodes/{id}/actions` | Серверные разрешённые действия и typed reasons блокировки. |
| `GET /v1/craft/workspace?node_id={id}` | Новый DTO: рюкзак, доступные storage summaries, станции, выбранный контекст, revisions; node необязателен. |
| `GET /v1/craft/storages/{id}` | Содержимое только доступного storage, вместимость, placements, readonly reasons, revision. |
| `GET /v1/world/economy?node_id={id}` | Доход/расход/производство/потребление/долги, completed_tick, as_of, processing flag; личные детали только по правам. |
| `GET /v1/world/economy/transactions` | История с period/node/type/resource/owner/event фильтрами в пределах прав. |
| `GET /v1/world/economy/forecast?node_id={id}` | Прогноз и допущения, версия правил; не обещание фиксированного дохода. |
| `GET /v1/character/professions`, `/limits`, `/level-conditions` | Текущий player, пять профессий и ссылки на существующие craft skills; фильтры и причины. |
| `GET /v1/world/npcs`, `/npcs/{id}` | Доступные NPC, профессии, назначение, дом, состояние; частные поля по владельцу. |
| `GET /v1/world/events`, `/events/{id}` | Видимые события, scope, период, эффекты, действия и прогресс противодействия. |
| `GET /v1/quests`, `/quests/{id}` | Экземпляры/доступные шаблоны текущего игрока, цели, награды, claim state. |
| `GET /v1/notifications` | Уведомления только текущего пользователя. |
| `GET /v1/world/onboarding` | Вступление, доступность разового шалаша, claimed state, вариант доставки, стартовая площадка; grant не выдаётся чтением. |
| `GET /v1/character/home` | Назначенный ночлег, исправность/вместимость, льгота, ближайшая ночь, болезнь/recovery, as_of/server_time. |
| `GET /v1/world/nodes/{id}/expansions` | initial_open, unlocked, limit, цены следующих мест, policy revision; для огорода 1/10 до первой покупки. |
| `GET /v1/world/beds/{id}` | Locked/active, crop/state/yield, свой economy subject, разрешённые действия. |
| `GET /v1/world/economy/subjects/{id}` | Budget total/available/reserved, treasury total/collectible/lost, финансовый parent, обязательства, timestamps потерь. |
| `GET /v1/world/economy/subjects/{id}/obligations` | Суммы, база/ставка/revision, срок, recipient treasury, paid/unpaid; только доступный субъект. |
| `GET /v1/world/starter-orders` | Ограниченные оплаченные заказы без требований платной мастерской: предметы/количество, цена, получатель выручки, остаток бюджета заказчика. |

Node DTO: `id,type,parent_id,root_id,name,status,visibility,revision,coordinates,child_count,details,actions`. Тип задаёт строго проверяемую форму details; unknown type показывает безопасное неподдерживаемое состояние. Скрытое имя/owner нельзя «прятать» только CSS.

Добавлен тип BED — непосредственный ребёнок огорода с ordinal и unlock state. Placement DTO содержит exposure_class, base/extra wear, condition, processed_at и projected_at; lodging отдельно от URL просмотра. Economy DTO использует `{subject_id,budget:{amount,available,reserved},treasury:{amount,collectible,loss_total,next_loss_at,loss_rate},parent_subject_id,obligations,settled_through,catching_up}`; все суммы — decimal strings, `loss_rate` имеет документированную точность. Доход, внутренний перевод и личный взнос имеют разные operation types. Не отдавать содержимое чужих receipt lots через общий обзор города.

Workspace DTO различает `item_id` (тип), `inventory_id` (стопка), `instance_id` (единица оборудования), `storage_id` (место), `position` (место внутри), `container_id` (старый ID сундука). Станции/инструменты сохраняют текущий stack_size, а подробности стопки содержат её экземпляры. Station DTO: `{station_id, inventory_id|null, instance_id|null, source, node_id|null, durability|null, available, reasons}`. Public station без физического предмета имеет отдельную политику использования, не поддельную бесконечную прочность.

## Предпросмотр и команды

Все новые команды принимают `request_key` (16–80 безопасных символов), `expected_revisions`, при необходимости `quote_id`. User/actor исполнитель определяется сервером; клиентский owner не предоставляет прав. Quote доступен только своему пользователю и подписан/хранится сервером, фиксирует тип действия, источники, назначение, количество, цену, версию правил и срок. Предпросмотр может хранить quote, но ничего не резервирует и не списывает. На commit условия проверяются снова.

| Предпросмотр / команда POST | Назначение |
|---|---|
| `/v1/world/buildings/preview` → `/buildings/construct` | Участок, шаблон, ресурсы, цена/время; создаёт construction. |
| `/v1/world/buildings/{id}/repair-preview` → `/repair` | Материалы, цена, восстановление и источники. |
| `/v1/world/buildings/{id}/rooms/preview` → `/rooms` | Комната, площадь, требования. |
| `/v1/world/nodes/{id}/plots/preview` → `/plots` | Участок допустимого типа. |
| `/v1/world/placements/preview` → `/install`, `/move`, `/uninstall` | Точный inventory ID и storage/position назначения. |
| `/v1/craft/workspace/preview` → `/v1/craft/workspace-command` | craft/repair/transfer с контекстом, конкретной станцией и разрешёнными источниками. |
| `/v1/world/buildings/{id}/demolish-preview` → `/demolish` | Причины блокировки, возврат по правилам; без скрытого уничтожения содержимого. |
| `/v1/world/npcs/{id}/assign`, `/unassign`, `/train` | Конкретный пост/курс; version/quote для цены и требований. |
| `/v1/world/production/preview` → `/production/orders` | Recipe, actor NPC, station, input/output storage, партии; сервер резервирует после принятия. |
| `/v1/world/production/orders/{id}/cancel` | Освобождение ещё не потреблённых резервов по сохранённым правилам. |
| `/v1/world/economy/subjects/{id}/fund-preview` → `/fund` | Явное внесение личных Cr в бюджет объекта; источник — текущий user, не казна. |
| `/v1/world/events/{id}/actions` | Поддерживаемое действие противодействия; серверный расход. |
| `/v1/quests/{id}/accept`, `/claim`, `/abandon` | Принятие шаблона или действие над экземпляром, проверка владельца. |
| `/v1/notifications/{id}/read` | Идемпотентная отметка чтения. |

Все сокращённые правые пути в строке таблицы сохраняют базу левого ресурса, кроме полностью указанного пути к workspace-command. До реализации BE:A2 фиксирует полные маршруты OpenAPI, исключая неоднозначность router rules.

### Команды дополнения (полные пути)

| Предпросмотр POST | Команда POST | Проверяемый эффект |
|---|---|---|
| `/v1/world/onboarding/preview` | `/v1/world/onboarding/join` | Одно вступление/право площадки, без автоматического шалаша/бюджета 500. |
| `/v1/world/starter-grants/{code}/preview` | `/v1/world/starter-grants/{code}/claim` | Разовый шалаш, доставка в рюкзак или явный deploy; grant_code не меняется с revision. |
| `/v1/world/shelters/preview` | `/v1/world/shelters/deploy` | Тот же item instance становится действующим укрытием на площадке. |
| `/v1/world/shelters/{id}/fold-preview` | `/v1/world/shelters/{id}/fold` | Снятие без потери ID/прочности, проверка жильцов/места назначения. |
| `/v1/character/lodging/preview` | `/v1/character/lodging/assign` | Назначение допустимого спального места; не мгновенное лечение прошлой ночи. |
| `/v1/character/treatment/preview` | `/v1/character/treatment/start` | Зафиксированный способ лечения, предметы/Cr/срок; бесплатно доступно естественное восстановление. |
| `/v1/world/economy/subjects/{id}/collect-preview` | `/v1/world/economy/subjects/{id}/collect` | Только собственная казна→свой budget; точная доступная сумма после наступивших потерь. |
| `/v1/world/economy/collect-batches/preview` | `/v1/world/economy/collect-batches` | Ручной сбор выбранных собственных объектов с отдельными child results, не автоматический каскад вверх. |
| `/v1/world/economy/obligations/{id}/pay-preview` | `/v1/world/economy/obligations/{id}/pay` | Budget должника→treasury получателя, заданного invoice; новая receipt с собственным сроком потерь. |
| `/v1/world/economy/subjects/{id}/allocate-preview` | `/v1/world/economy/subjects/{id}/allocate` | Целевое финансирование бюджета ребёнка из бюджета родителя. |
| `/v1/world/nodes/{id}/expansions/preview` | `/v1/world/nodes/{id}/expansions/buy` | Kind/quantity, unit prices/total, funding account, revisions; право и дебет атомарны. |
| `/v1/world/beds/{id}/sow-preview` | `/v1/world/beds/{id}/sow` | Расход семян/воды и начало цикла только открытой грядки. |
| `/v1/world/beds/{id}/water-preview` | `/v1/world/beds/{id}/water` | Расход воды/услуги по правилам культуры. |
| `/v1/world/beds/{id}/harvest-preview` | `/v1/world/beds/{id}/harvest` | Урожай в выбранный storage, без одновременного создания Cr. |
| `/v1/world/starter-orders/{id}/preview` | `/v1/world/starter-orders/{id}/fulfill` | Сдача предметов и зачисление реальной оплаты в treasury стартового хозяйства, без бесконечного бюджета. |

Preview collect содержит receipt snapshot/digest, exact amount, as_of, loss already applied, tax obligation estimate и destination budget, заданный сервером. Новый доход после preview не включается в повтор старой команды. Pay-invoice фиксирует остаток обязательства, его revision/получателя; partial payment, если разрешён правилом, не гасит остаток. Client не присылает собственную ставку разграбления/налога или дату ночи как источник истины.

Для expansion `initial_open`, `unlocked_before`, `quantity`, `unit_prices`, `total_price`, `policy_revision`, `budget_available` приходят с сервера. Составная команда `fund_and_buy` содержит явный `personal_contribution`, подтверждённый quote и прежний request_key при повторе; финансирование/покупка либо фиксируются вместе, либо откатываются вместе. Для комнат используются те же правила денежного quote существующего construct/purchase use case.

Для установки/использования оборудования дополнительно обязателен `instance_id` и revision экземпляра; сервер проверяет его принадлежность указанной inventory row. Установка отделяет одну единицу от стопки. Для сундука instance_id отсутствует: его идентичность уже задана существующим inventory/container ID. Новый API не принимает комбинацию «чужой instance_id + свой inventory_id».

Пример установки (идентификаторы иллюстративны):

```json
{
  "request_key": "world-install-37-00000001",
  "inventory_id": 501,
  "destination_storage_id": 204,
  "destination_position": 2,
  "quote_id": "quote-example",
  "expected_revisions": {"inventory:501": 4, "storage:204": 7, "node:82": 3}
}
```

Успех: `{command_id,operation_id,replayed,message,result,changed:[{type,id,revision}],server_time}`. Повтор возвращает тот же результат операции и актуальный перечень revisions; state перечитывается при необходимости. `result` не переписывается новым расчётом цены. Переход из craft в room инвалидирует обе проекции; не требуется отдавать весь каталог после каждого перемещения.

Ошибки: `{code,message,errors,reasons,refresh,correlation_id}`. Причина: `{code,target,required,actual,action_link?}`. Коды: `not_owner`, `forbidden_storage`, `incompatible_slot`, `no_capacity`, `station_broken`, `building_inactive`, `insufficient_materials`, `insufficient_credit`, `requirements_not_met`, `item_reserved`, `revision_conflict`, `quote_expired`, `idempotency_conflict`, `simulation_catching_up`. Validation=422; конфликт состояния=409; auth=401; запрет=403 либо 404 при сокрытии объекта; лимит=429; недоступный модуль=503. Для catch-up возвращаются retry_after и текущий completed_tick, выполнение экономики внутри HTTP запрещено. Trace, SQL и содержимое чужих объектов не возвращаются.

После timeout/5xx клиент сохраняет полную команду и прежний ключ отдельно по аккаунту. После 409 обновляет контекст и показывает изменившееся условие; новая подтверждённая операция получает новый ключ. Одна команда не должна автоматически повторяться с новой ценой. Одинаковый request_key с другой ценой/станцией/назначением всегда конфликтует.

Дополнительные коды: `grant_already_claimed`, `lodging_unavailable`, `station_not_deployed`, `expansion_limit`, `bed_locked`, `harvest_not_ready`, `budget_reserved`, `obligation_already_paid`, `treasury_changed`, `treasury_catching_up`. При catch-up до сбора показывается время следующего обновления; это не повод сгенерировать новый ключ и повторить покупку/сбор автоматически. Theft/night/wear обрабатываются только доверенными CLI jobs, публичного «начислить доход/убыток» endpoint нет.

## Реализованные команды казны (2026-09-27)

Текущий исполняемый контракт описан в [world-economy.openapi.json](api/world-economy.openapi.json), состояние и ограничения — в [восьмом блоке](world-treasury-implementation.md). Доступны `economy`, `obligations`, пары `collect-preview/collect`, `pay-preview/pay`, `finance-policy-preview/finance-policy`. Изменение финансовых правил требует `p-admin` и `world-manage`; сбор/оплата — фактического владельца, без административного обхода.

Фактическая ошибка ожидания потерь: HTTP 409 `{code: "TREASURY_CATCHING_UP", message, details: {retry_after: 30}}`. Worker выполняет потери, HTTP только ждёт и вручную собирает уже рассчитанные поступления. Для сбора применяется до 100 старейших receipt на команду; `more_pending` означает наличие следующей партии. Quote содержит точные `collected`, `reserved_for_parent`, `budget_after`, `available_after`, `treasury_after`, версию и родителя. Платёж принимает только `obligation_id` и подтверждение quote, сумма/получатель фиксированы обязательством. Новые команды возвращают общий `CommandResult`; денежные поля личного кошелька в collect/pay не добавляются.

Существующие выше расширенные error/result-примеры остаются целевым контрактом будущих частей плана; реализованные DTO и uppercase-коды берутся из OpenAPI и кода сервисов. Миграции, активация и проверки не запускались.

## Реализованные начальные заказы (2026-09-27)

[Контракт заказов](api/world-orders.openapi.json) добавляет `orders`, `order-items`, `order-stock` и пары `order-publish-preview/order-publish`, `order-deliver-preview/order-deliver`, `order-cancel-preview/order-cancel` у поселения. Список публичен, выбранные стопки — только собственные. Публикация резервирует всю стоимость в существующем бюджете; оплата и расход сырья фиксируются одной командой, получатель — казна стартовой стоянки игрока в этом поселении. Сдача не создаёт личные кредиты и не выдаёт предметы повторно.

Начальные условия стоянки фиксируются в заказе и требуют явного подтверждения при публикации и первой сдаче. Существующая опубликованная политика сохраняется. Отмена/expiry освобождает только неиспользованный резерв; deadline проверяется сервером до сдачи независимо от готовности worker. [Ограничения и продолжение](world-orders-implementation.md). Код не проверялся и не запускался на серверах.

## Админка и доставка обновлений

Покупка готового помещения описана в [world-premises.openapi.json](api/world-premises.openapi.json): `GET /v1/world/nodes/{id}/premises` и пары `premises-publish-preview/premises-publish`, `premises-buy-preview/premises-buy`, `premises-withdraw-preview/premises-withdraw`. Публикация и снятие выполняются у поселения, покупка — у собственной площадки. Preview фиксирует цену, площадь, места, защиту, бюджет и казну получателя. Оплата и создание BUILDING→ROOM/placement атомарны; старые покупки не меняются при снятии предложения. [Состояние и ограничения](world-premises-implementation.md).

Начальный редактор находится в существующем Yii backend: `/world/...`, отдельные permissions поверх RBAC, POST/CSRF для изменений. Preview выдаёт diff + digest + base revision; publish отвергает устаревший просмотр. Публичному API не нужен открытый generic PATCH произвольных колонок. Административная смена владельца/дерева имеет отдельный use case и аудит.

Первый клиент использует ограниченный polling видимой страницы и обновление после команд; polling приостанавливается при уходе/смене аккаунта и не запускает экономику. Контракты `changed/revision/notification_id` позволяют позже добавить SSE/WebSocket без изменения доменных команд.
# Дополнение: строительство по времени

Контракт marker 19: [world-construction.openapi.json](api/world-construction.openapi.json), [описание реализации](world-construction-implementation.md). Premises поддерживает ready/construction; новый owner-only список и pause/resume/cancel используют общие quote/command. Материалы резервируются из рюкзака, отмена требует места для полного возврата. Код не проходил игровую приёмку.
