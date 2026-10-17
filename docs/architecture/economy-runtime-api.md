# API экономики закрытого теста

Срез реализации от 2026-10-17. Базовый путь — `/v1/world`. Используется существующая Bearer-авторизация. Установка схемы не публикует профиль: публикация выполняется отдельно с предварительным просмотром и проверкой digest. Допуск закрытого теста проверяется сервером через RBAC; запись участника сама по себе права не выдаёт.

## Состояние и журнал

Журнал дополнен связями `actor_user_id`, `node_id`, `profile_id`, `profile_version`, `from`, `to`, `item_id`, `balance_before_cr`, `balance_after_cr`, `quantity_before`, `quantity_after`, `transfer_ids[]`, `transfer_phase` (`create/claim/cancel`). Недоступные исторические поля — `null`; чужие счета редактируются до безопасного endpoint без ID/объекта/баланса. Вещи показывают количество движения; исторические остатки стопок не восстанавливаются из текущего инвентаря. Подробная [матрица покрытия и миграций](economy-journal-migrations.md).

`GET /economy` возвращает `enabled`, `profile_version`, `admission`, `health`, `goals`, `quotas`, `feedback_path`, `server_time`. При отсутствии опубликованного профиля `enabled=false`; клиент не создаёт локальные цели или награды. `feedback_path` — внутренний путь темы форума.

- `health`: `hp` (0–100), `max_hp=100`, `revision`, `foods[{code,name,restore_hp,quantity}]`, `server_time`. HP учитываются отдельно от ночной болезни.
- `goals[]`: `code`, `claimed`, `funded`, `can_claim`, точная строка `credits_cr`, `steps[{code,completed,quantity,required}]`. Выполнение подтверждают серверные факты.
- `quotas[]`: контракт [клиентского парсера](../../../frontend/project/src/services/api/worldEconomyQuota.ts). Дневные лимиты восстанавливаются по московскому времени; клиент показывает серверный `restores_at`.

Дополнительно: `personal_credit_cr`, `target_start_credit_cr` (точные строки), `second_milestone_day` (проверенный срок). Шаг цели содержит `action_href` — внутренний путь мира к доступному действию. Источники возвращают `output_storage_id` принятой партии, `actions[].tool_names`, `actions[].experience` и `actions[].available_at`; клиент показывает опыт, срок кулдауна/дневного восстановления и не меняет хранилище уже принятой партии.

`GET /economy/history?source=commands&page=1&q=` читает существующие журналы без копирования балансов. `source`: `commands`, `credits`, `items`, `transfers`, `wallet`, `health`. Поиск выполняется сервером, страница содержит 20 записей. Ответ: `items`, `_meta{totalCount,pageCount,currentPage,perPage}`, `server_time`.

Запись содержит `id`, `source`, `operation_id`, `created_at`, `kind`, `classification`, `amount_cr`, `quantity`, `balance_before_cr`, `balance_after_cr`. ID игровой команды — строка; ID старого журнала может быть числом. Денежные значения — строки или null; старый журнал переводов сохраняет свою точность. История не раскрывает хэши/пароли переводов и остатки чужих счетов. Необязательный `user_id` доступен только управляющему миром или администратору.

## Еда и цели

Для новой команды нужен новый `request_key` (либо заголовок `Idempotency-Key`); после потери ответа повторяются прежние ключ и тело.

| POST | Поля JSON кроме `request_key` | Результат/ограничение |
| --- | --- | --- |
| `/economy/food` | `code`, целое `health_revision` из GET | Атомарное потребление и изменение HP. `HEALTH_CHANGED` требует перечитать состояние; `FOOD_NOT_NEEDED` сохраняет еду при полных HP |
| `/economy/goals/claim` | `code`: `day-1` или `day-2` | Однократная награда при выполненных фактах и обеспеченном резерве |
| `/economy/goals/fund` | `code`, целое `settlement_id`, целое `participants` (1–1000) | Резерв из существующего бюджета поселения; требуются права `world-manage` |

Денежная награда цели направляется в казну стоянки. Сбор в её бюджет сохраняет действующие отчисления и не пополняет личный кошелёк. Начальные 250 Cr готовятся существующим переводом; нового банка и покупки кредитов нет.

## Расширение хранения

`GET /economy/storage/{id}` возвращает `storage_id`, постоянную `capacity`, `options[{code,slots,price_cr,capacity_after}]`, `profile_version`, `writable`, `server_time`. Временные места рюкзака не превращаются в постоянные. Пустые `options` означают отсутствие доступного пакета.

`POST /economy/storage/{id}/expand-preview` с `{code}` возвращает обычный quote: `quote_id`, `expected_revisions`, сроки и `terms`. Условия включают `capacity_before`, `capacity_after`, `price_cr`, `payer=personal_wallet` и версию профиля.

`POST /economy/storage/{id}/expand` принимает `{code,request_key,quote_id,expected_revisions}`. Полный объект ревизий копируется из preview. Списание выполняется с личного баланса; старые предметы и вместимость сохраняются. `STORAGE_EXPANSION_CHANGED` требует нового расчёта. При неопределённом результате клиент сохраняет исходную команду для точного повтора.

## Проверки и границы приёмки

Исполняемые сценарии: `world-economy-runtime.php`, `world-economy-sources.php`, `world-economy-gameplay.php`, `world-economy-race.php` в `yii2/tests/release`. Недельный сценарий проходит реальными командами до дома и склада на 50 мест с ночлегом и финансовой сверкой. Источники проверяются по всем 13 действиям; гонки — на PostgreSQL и MariaDB. Браузерная приёмка и решение о выпуске остаются отдельными. [Инструкция запуска](../operations/world-mvp-closed-test.md).
