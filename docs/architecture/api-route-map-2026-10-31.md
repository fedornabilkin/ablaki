# Карта API и запросов SPA · 31 октября 2026

Снимок регистрации маршрутов после очистки. Решения и совместимость: [контракт](../architecture/api-consolidation.md); результаты: [отчёт](../operations/api-audit-2026-10-31.md).

## Маршруты backend

Источник — реальный Yii UrlManager, включая развёрнутые REST-правила. 290 правил, 0 отсутствующих действий, 0 совпадений пары HTTP-метод/скомпилированный шаблон. Несколько методов в одной строке считаются одним правилом. OPTIONS включены; динамические серверные HTML-маршруты крафта исключены. Это проверка регистрации и создания action, а не выполнение всех операций.

Обновление: `php yii2/tests/release/api-route-catalog.php --json` из корня backend. Проверка без `--json` включена в CI и завершается ошибкой при отсутствующем обработчике либо точном дублировании правила.

| Метод | Адрес | Действие |
| --- | --- | --- |
| POST | `v1/world/objects/<id:\d+>/starter-reserve` | `world/start-api/reserve` |
| GET | `v1/world/economy` | `world/economy-api/index` |
| GET | `v1/world/economy/progression` | `world/economy-api/progression` |
| GET | `v1/world/economy/kitchen/<id:\d+>` | `world/economy-api/kitchen` |
| GET | `v1/world/economy/history` | `world/economy-api/history` |
| POST | `v1/world/economy/food` | `world/economy-api/food` |
| POST | `v1/world/economy/goals/claim` | `world/economy-api/goal` |
| POST | `v1/world/economy/goals/fund` | `world/economy-api/fund-goal` |
| POST | `v1/world/objects/<id:\d+>/repair` | `world/build-api/repair` |
| GET | `v1/world/objects/<id:\d+>/mining` | `world/mining-api/view` |
| GET | `v1/world/objects/<id:\d+>/budget` | `world/account-api/view` |
| POST | `v1/world/objects/<id:\d+>/budget` | `world/account-api/create` |
| POST | `v1/world/objects/<id:\d+>/gather` | `world/supply-api/create` |
| GET | `v1/world/objects/<id:\d+>/gather` | `world/supply-api/view` |
| GET | `v1/world/objects` | `world/object-api/index` |
| GET | `v1/world/objects/<id:\d+>` | `world/object-api/view` |
| GET | `v1/world/templates` | `world/template-api/index` |
| GET | `v1/world/events` | `world/event-api/index` |
| GET | `v1/world/economy/storage/<id:\d+>` | `world/economy-api/storage` |
| POST | `v1/world/economy/storage/<id:\d+>/expand-preview` | `world/economy-api/storage-expand-preview` |
| POST | `v1/world/economy/storage/<id:\d+>/expand` | `world/economy-api/storage-expand` |
| GET | `v1/world/builds` | `world/build-api/index` |
| GET | `v1/world/builds/active` | `world/build-api/active` |
| GET | `v1/world/builds/<id:\d+>` | `world/build-api/view` |
| POST | `v1/world/builds` | `world/build-api/create` |
| POST | `v1/world/builds/<id:\d+>/settings` | `world/build-api/settings` |
| GET | `v1/world/start` | `world/start-api/index` |
| POST | `v1/world/start` | `world/start-api/create` |
| GET | `v1/world/nodes/<id:\d+>/presence` | `world/api-node/presence` |
| POST | `v1/world/nodes/<id:\d+>/presence` | `world/api-node/presence` |
| GET | `v1/world` | `world/api-node/index` |
| GET | `v1/world/nodes/<id:\d+>/construction` | `world/api-construction/construction` |
| GET | `v1/world/nodes/<id:\d+>/building-operation` | `world/api-building/building-operation` |
| GET | `v1/world/nodes/<id:\d+>/building-repair` | `world/api-building/building-repair` |
| GET | `v1/world/nodes/<id:\d+>/demolition` | `world/api-demolition/demolition` |
| GET | `v1/world/nodes/<id:\d+>/demolitions` | `world/api-demolition/demolitions` |
| POST | `v1/world/nodes/<id:\d+>/demolish-preview` | `world/api-demolition/demolish-preview` |
| POST | `v1/world/nodes/<id:\d+>/demolish` | `world/api-demolition/demolish` |
| GET | `v1/world/nodes/<id:\d+>/repair-contracts` | `world/api-building/repair-contracts` |
| POST | `v1/world/nodes/<id:\d+>/repair-contract-preview` | `world/api-building/repair-contract-preview` |
| POST | `v1/world/nodes/<id:\d+>/repair-contract` | `world/api-building/repair-contract-execute` |
| POST | `v1/world/nodes/<id:\d+>/building-repair-preview` | `world/api-building/building-repair-preview` |
| POST | `v1/world/nodes/<id:\d+>/building-repair` | `world/api-building/building-repair-execute` |
| POST | `v1/world/nodes/<id:\d+>/building-<operation:pause&#124;resume>-preview` | `world/api-building/building-operation-preview` |
| POST | `v1/world/nodes/<id:\d+>/building-<operation:pause&#124;resume>` | `world/api-building/building-operation-execute` |
| POST | `v1/world/nodes/<id:\d+>/construction-<operation:pause&#124;resume&#124;cancel>-preview` | `world/api-construction/construction-preview` |
| POST | `v1/world/nodes/<id:\d+>/construction-<operation:pause&#124;resume&#124;cancel>` | `world/api-construction/construction-execute` |
| GET | `v1/world/equipment/<id:\d+>/wear` | `world/api-housing/equipment-wear` |
| GET | `v1/world/nodes/<id:\d+>/housing` | `world/api-housing/housing` |
| POST | `v1/world/nodes/<id:\d+>/housing-<operation:lodge&#124;leave>-preview` | `world/api-housing/housing-preview` |
| POST | `v1/world/nodes/<id:\d+>/housing-<operation:lodge&#124;leave>` | `world/api-housing/housing-execute` |
| GET | `v1/world/nodes/<id:\d+>/nights` | `world/api-shelter/nights` |
| GET | `v1/world/nodes/<id:\d+>/shelter` | `world/api-shelter/shelter` |
| POST | `v1/world/nodes/<id:\d+>/shelter-<operation:claim&#124;deploy&#124;fold&#124;lodge&#124;leave&#124;repair>-preview` | `world/api-shelter/shelter-preview` |
| POST | `v1/world/nodes/<id:\d+>/shelter-<operation:claim&#124;deploy&#124;fold&#124;lodge&#124;leave&#124;repair>` | `world/api-shelter/shelter-execute` |
| GET | `v1/world/nodes/<id:\d+>/placement-options` | `world/api-storage/placement-options` |
| GET | `v1/world/nodes/<id:\d+>/harvest` | `world/api-harvest/harvest` |
| POST | `v1/world/nodes/<id:\d+>/harvest-<operation:price&#124;buy&#124;withdraw>-preview` | `world/api-harvest/harvest-preview` |
| POST | `v1/world/nodes/<id:\d+>/harvest-<operation:price&#124;buy&#124;withdraw>` | `world/api-harvest/harvest-execute` |
| GET | `v1/world/nodes/<id:\d+>/garden` | `world/api-garden/garden` |
| GET | `v1/world/nodes/<id:\d+>/warehouse` | `world/api-warehouse/warehouse` |
| POST | `v1/world/nodes/<id:\d+>/warehouse-expand-preview` | `world/api-warehouse/warehouse-expand-preview` |
| POST | `v1/world/nodes/<id:\d+>/warehouse-expand` | `world/api-warehouse/warehouse-expand` |
| GET | `v1/world/nodes/<id:\d+>/equipment-expansion` | `world/api-garden/equipment-expansion` |
| POST | `v1/world/nodes/<id:\d+>/equipment-expand-preview` | `world/api-garden/equipment-expand-preview` |
| POST | `v1/world/nodes/<id:\d+>/equipment-expand` | `world/api-garden/equipment-expand` |
| POST | `v1/world/nodes/<id:\d+>/garden-<operation:publish&#124;withdraw&#124;buy&#124;expand>-preview` | `world/api-garden/garden-preview` |
| POST | `v1/world/nodes/<id:\d+>/garden-<operation:publish&#124;withdraw&#124;buy&#124;expand>` | `world/api-garden/garden-execute` |
| GET | `v1/world/nodes/<id:\d+>/premises` | `world/api-premises/premises` |
| POST | `v1/world/nodes/<id:\d+>/premises-publish-preview` | `world/api-premises/premises-publish-preview` |
| POST | `v1/world/nodes/<id:\d+>/premises-publish` | `world/api-premises/premises-publish` |
| POST | `v1/world/nodes/<id:\d+>/premises-buy-preview` | `world/api-premises/premises-buy-preview` |
| POST | `v1/world/nodes/<id:\d+>/premises-buy` | `world/api-premises/premises-buy` |
| POST | `v1/world/nodes/<id:\d+>/premises-withdraw-preview` | `world/api-premises/premises-withdraw-preview` |
| POST | `v1/world/nodes/<id:\d+>/premises-withdraw` | `world/api-premises/premises-withdraw` |
| GET | `v1/world/achievements` | `world/api-achievement/achievements` |
| POST | `v1/world/achievements/publish-preview` | `world/api-achievement/achievement-publish-preview` |
| POST | `v1/world/achievements/publish` | `world/api-achievement/achievement-publish` |
| GET | `v1/world/crops` | `world/api-cultivation/crops` |
| POST | `v1/world/crops/publish-preview` | `world/api-cultivation/crop-publish-preview` |
| POST | `v1/world/crops/publish` | `world/api-cultivation/crop-publish` |
| GET | `v1/world/beds/<id:\d+>/cultivation` | `world/api-cultivation/cultivation` |
| POST | `v1/world/beds/<id:\d+>/<action:dig&#124;sow&#124;water&#124;harvest&#124;cancel>-preview` | `world/api-cultivation/cultivation-preview` |
| POST | `v1/world/beds/<id:\d+>/<action:dig&#124;sow&#124;water&#124;harvest&#124;cancel>` | `world/api-cultivation/cultivation-execute` |
| GET | `v1/world/notifications` | `world/api-notification/notifications` |
| POST | `v1/world/notifications/<id:\d+>/read` | `world/api-notification/notification-read` |
| GET | `v1/world/notification-preferences` | `world/api-notification/notification-preferences` |
| POST | `v1/world/notification-preferences` | `world/api-notification/notification-configure` |
| GET | `v1/world/professions` | `world/api-profession/professions` |
| POST | `v1/world/professions/publish-preview` | `world/api-profession/profession-publish-preview` |
| POST | `v1/world/professions/publish` | `world/api-profession/profession-publish` |
| POST | `v1/world/professions/<id:\d+>/<action:enroll&#124;level-up>-preview` | `world/api-profession/profession-preview` |
| POST | `v1/world/professions/<id:\d+>/<action:enroll&#124;level-up>` | `world/api-profession/profession-execute` |
| GET | `v1/world/nodes/<id:\d+>/finance-hierarchy` | `world/api-finance/finance-hierarchy` |
| GET | `v1/world/nodes/<id:\d+>/finance-report` | `world/api-finance/finance-report` |
| GET | `v1/world/nodes/<id:\d+>/treasury-receipts` | `world/api-finance/treasury-receipts` |
| GET | `v1/world/nodes/<id:\d+>/economy` | `world/api-budget/economy` |
| GET | `v1/world/nodes/<id:\d+>/orders` | `world/api-order/orders` |
| GET | `v1/world/nodes/<id:\d+>/order-items` | `world/api-order/order-items` |
| GET | `v1/world/nodes/<id:\d+>/order-stock` | `world/api-order/order-stock` |
| POST | `v1/world/nodes/<id:\d+>/order-publish-preview` | `world/api-order/order-publish-preview` |
| POST | `v1/world/nodes/<id:\d+>/order-publish` | `world/api-order/order-publish` |
| POST | `v1/world/nodes/<id:\d+>/order-deliver-preview` | `world/api-order/order-deliver-preview` |
| POST | `v1/world/nodes/<id:\d+>/order-deliver` | `world/api-order/order-deliver` |
| POST | `v1/world/nodes/<id:\d+>/order-cancel-preview` | `world/api-order/order-cancel-preview` |
| POST | `v1/world/nodes/<id:\d+>/order-cancel` | `world/api-order/order-cancel` |
| GET | `v1/world/nodes/<id:\d+>/obligations` | `world/api-finance/obligations` |
| POST | `v1/world/nodes/<id:\d+>/collect-preview` | `world/api-finance/collect-preview` |
| POST | `v1/world/nodes/<id:\d+>/collect` | `world/api-finance/collect` |
| POST | `v1/world/nodes/<id:\d+>/pay-preview` | `world/api-finance/pay-preview` |
| POST | `v1/world/nodes/<id:\d+>/pay` | `world/api-finance/pay` |
| POST | `v1/world/nodes/<id:\d+>/finance-policy-preview` | `world/api-finance/finance-policy-preview` |
| POST | `v1/world/nodes/<id:\d+>/finance-policy` | `world/api-finance/finance-policy` |
| POST | `v1/world/nodes/<id:\d+>/invest-preview` | `world/api-budget/invest-preview` |
| POST | `v1/world/nodes/<id:\d+>/invest` | `world/api-budget/invest` |
| POST | `v1/world/nodes/<id:\d+>/budget-grant-preview` | `world/api-budget/budget-grant-preview` |
| POST | `v1/world/nodes/<id:\d+>/budget-grant` | `world/api-budget/budget-grant` |
| POST | `v1/world/nodes/<id:\d+>/supplies-<operation:starter&#124;gather>-preview` | `world/api-workspace/supplies-preview` |
| POST | `v1/world/nodes/<id:\d+>/supplies-<operation:starter&#124;gather>` | `world/api-workspace/supplies-execute` |
| GET | `v1/world/workspace` | `world/api-workspace/workspace` |
| GET | `v1/world/storage/recovery` | `world/api-storage/recovery` |
| POST | `v1/world/storage/recovery-preview` | `world/api-storage/recovery-preview` |
| POST | `v1/world/storage/recover` | `world/api-storage/recover` |
| POST | `v1/world/workspace/preview` | `world/api-workspace/workspace-preview` |
| POST | `v1/world/workspace/craft` | `world/api-workspace/workspace-craft` |
| GET | `v1/world/storages` | `world/api-storage/storages` |
| GET | `v1/world/storage/<id:\d+>` | `world/api-storage/storage` |
| POST | `v1/world/storage/transfer-preview` | `world/api-storage/storage-transfer-preview` |
| POST | `v1/world/storage/transfer` | `world/api-storage/storage-transfer` |
| POST | `v1/world/storage/chest-repair-preview` | `world/api-storage/chest-repair-preview` |
| POST | `v1/world/storage/chest-repair` | `world/api-storage/chest-repair` |
| GET | `v1/world/onboarding` | `world/api-membership/onboarding` |
| POST | `v1/world/onboarding/preview` | `world/api-membership/join-preview` |
| POST | `v1/world/onboarding/join` | `world/api-membership/join` |
| GET | `v1/world/nodes/<id:\d+>/campsite` | `world/api-node/campsite` |
| GET | `v1/world/nodes/<id:\d+>` | `world/api-node/node` |
| GET | `v1/world/nodes/<id:\d+>/children` | `world/api-node/children` |
| GET | `v1/world/nodes/<id:\d+>/navigation` | `world/api-node/navigation` |
| GET | `v1/world/nodes/<id:\d+>/map` | `world/api-node/map` |
| POST | `v1/world/nodes/<id:\d+>/map-explore-preview` | `world/api-node/map-explore-preview` |
| POST | `v1/world/nodes/<id:\d+>/map-explore` | `world/api-node/map-explore` |
| POST | `v1/world/nodes/<id:\d+>/map-buy-preview` | `world/api-node/map-buy-preview` |
| POST | `v1/world/nodes/<id:\d+>/map-buy` | `world/api-node/map-buy` |
| GET | `v1/world/nodes/<id:\d+>/statistics` | `world/api-node/statistics` |
| GET | `v1/world/nodes/<id:\d+>/actions` | `world/api-node/actions` |
| POST | `v1/world/nodes/<id:\d+>/move-preview` | `world/api-node/move-preview` |
| POST | `v1/world/nodes/<id:\d+>/move` | `world/api-node/move` |
| POST | `v1/world/nodes/<id:\d+>/archive-preview` | `world/api-node/archive-preview` |
| POST | `v1/world/nodes/<id:\d+>/archive` | `world/api-node/archive` |
| OPTIONS | `v1/world/<path:.*>` | `world/api-node/options` |
| * | `login` | `site/login` |
| * | `login-key/<key:[\w\-]+>` | `site/login-key` |
| * | `logout` | `site/logout` |
| * | `registration` | `site/registration` |
| GET | `v1/users/data` | `v1/user/data` |
| GET | `v1/users/profile` | `v1/user/profile` |
| GET | `v1/users/last` | `v1/user/last` |
| GET | `v1/users/online` | `v1/user/online` |
| GET | `v1/users/online-count` | `v1/user/online-count` |
| GET | `v1/users/visited` | `v1/user/visited` |
| GET | `v1/users/referrals` | `v1/user/referrals` |
| POST | `v1/users/heartbeat` | `v1/user/heartbeat` |
| GET | `v1/users/wall/<login:[-a-zA-Z0-9_\.@]+>` | `v1/user/wall` |
| PATCH | `v1/users/wall` | `v1/user/wall-update` |
| GET,HEAD | `v1/users` | `v1/user/index` |
| GET | `v1/tips/random` | `v1/tips/random` |
| GET | `v1/history/balance` | `v1/history/balance` |
| GET | `v1/history/balance-type` | `v1/history/balance-type` |
| GET | `v1/history/rating` | `v1/history/rating` |
| GET | `v1/history/rating-type` | `v1/history/rating-type` |
| GET,HEAD | `v1/history` | `v1/history/index` |
| POST | `v1/rating/everyday` | `v1/rating/everyday` |
| GET | `v1/rating/everyday` | `v1/rating/everyday` |
| GET | `v1/stat/recent-games` | `v1/stat/recent-games` |
| GET | `v1/stat/top` | `v1/stat/top` |
| GET | `v1/stat/user/<login:[-a-zA-Z0-9_\.@]+>` | `v1/stat/user` |
| GET,HEAD | `v1/stat` | `v1/stat/index` |
| POST | `v1/bonus/everyday` | `v1/bonus/everyday` |
| GET | `v1/bonus/everyday` | `v1/bonus/everyday` |
| GET | `v1/bonus/recipients` | `v1/bonus/recipients` |
| GET | `v1/bonus/fund` | `v1/bonus/fund` |
| GET | `v1/bonus/my-fund` | `v1/bonus/my-fund` |
| GET | `v1/bonus/available` | `v1/bonus/available` |
| GET | `v1/exchange/remove` | `v1/exchange/remove` |
| GET | `v1/exchange/my` | `v1/exchange/my` |
| GET | `v1/exchange/history` | `v1/exchange/history` |
| GET | `v1/exchange/available-count` | `v1/exchange/available-count` |
| PUT,PATCH | `v1/exchange/<id:\d[\d,]*>` | `v1/exchange/update` |
| DELETE | `v1/exchange/<id:\d[\d,]*>` | `v1/exchange/delete` |
| POST | `v1/exchange` | `v1/exchange/create` |
| GET,HEAD | `v1/exchange` | `v1/exchange/index` |
| * | `v1/exchange/<id:\d[\d,]*>` | `v1/exchange/options` |
| * | `v1/exchange` | `v1/exchange/options` |
| GET | `v1/transfer/history` | `v1/transfer/history` |
| PUT,PATCH | `v1/transfer/<id:\d[\d,]*>` | `v1/transfer/update` |
| DELETE | `v1/transfer/<id:\d[\d,]*>` | `v1/transfer/delete` |
| GET,HEAD | `v1/transfer/<id:\d[\d,]*>` | `v1/transfer/view` |
| POST | `v1/transfer` | `v1/transfer/create` |
| GET,HEAD | `v1/transfer` | `v1/transfer/index` |
| * | `v1/transfer/<id:\d[\d,]*>` | `v1/transfer/options` |
| * | `v1/transfer` | `v1/transfer/options` |
| GET | `v1/saper/stakes` | `v1/saper/stakes` |
| DELETE | `v1/saper/remove` | `v1/saper/remove` |
| GET | `v1/saper/my` | `v1/saper/my` |
| GET | `v1/saper/history` | `v1/saper/history` |
| GET | `v1/saper/history-kons` | `v1/saper/history-kons` |
| GET | `v1/saper/recent` | `v1/saper/recent` |
| GET | `v1/saper/summary` | `v1/saper/summary` |
| GET | `v1/saper/remove` | `v1/saper/remove` |
| POST | `v1/saper/start/<id:\d[\d,]*>` | `v1/saper/start` |
| GET | `v1/saper/start/<id:\d[\d,]*>` | `v1/saper/start` |
| POST | `v1/saper/play/<id:\d[\d,]*>` | `v1/saper/play` |
| POST | `v1/saper/double/<id:\d[\d,]*>` | `v1/saper/double` |
| DELETE | `v1/saper/<id:\d[\d,]*>` | `v1/saper/delete` |
| POST | `v1/saper` | `v1/saper/create` |
| GET,HEAD | `v1/saper` | `v1/saper/index` |
| * | `v1/saper/<id:\d[\d,]*>` | `v1/saper/options` |
| * | `v1/saper` | `v1/saper/options` |
| GET | `v1/duel/stakes` | `v1/duel/stakes` |
| DELETE | `v1/duel/remove` | `v1/duel/remove` |
| GET | `v1/duel/my` | `v1/duel/my` |
| GET | `v1/duel/history` | `v1/duel/history` |
| GET | `v1/duel/history-kons` | `v1/duel/history-kons` |
| GET | `v1/duel/recent` | `v1/duel/recent` |
| GET | `v1/duel/summary` | `v1/duel/summary` |
| POST | `v1/duel/play/<id:\d[\d,]*>` | `v1/duel/play` |
| DELETE | `v1/duel/<id:\d[\d,]*>` | `v1/duel/delete` |
| POST | `v1/duel` | `v1/duel/create` |
| GET,HEAD | `v1/duel` | `v1/duel/index` |
| * | `v1/duel/<id:\d[\d,]*>` | `v1/duel/options` |
| * | `v1/duel` | `v1/duel/options` |
| GET | `v1/five/stakes` | `v1/five/stakes` |
| DELETE | `v1/five/remove` | `v1/five/remove` |
| GET | `v1/five/my` | `v1/five/my` |
| GET | `v1/five/history` | `v1/five/history` |
| GET | `v1/five/history-kons` | `v1/five/history-kons` |
| GET | `v1/five/recent` | `v1/five/recent` |
| GET | `v1/five/summary` | `v1/five/summary` |
| POST | `v1/five/play/<id:\d[\d,]*>` | `v1/five/play` |
| DELETE | `v1/five/<id:\d[\d,]*>` | `v1/five/delete` |
| GET,HEAD | `v1/five/<id:\d[\d,]*>` | `v1/five/view` |
| POST | `v1/five` | `v1/five/create` |
| GET,HEAD | `v1/five` | `v1/five/index` |
| * | `v1/five/<id:\d[\d,]*>` | `v1/five/options` |
| * | `v1/five` | `v1/five/options` |
| GET | `v1/orel/stakes` | `v1/orel/stakes` |
| GET | `v1/orel/remove` | `v1/orel/remove` |
| DELETE | `v1/orel/remove` | `v1/orel/remove` |
| GET | `v1/orel/my` | `v1/orel/my` |
| GET | `v1/orel/history` | `v1/orel/history` |
| GET | `v1/orel/history-kons` | `v1/orel/history-kons` |
| GET | `v1/orel/kon-count` | `v1/orel/kon-count` |
| GET | `v1/orel/recent` | `v1/orel/recent` |
| GET | `v1/orel/summary` | `v1/orel/summary` |
| POST | `v1/orel/play/<id:\d[\d,]*>` | `v1/orel/play` |
| DELETE | `v1/orel/<id:\d[\d,]*>` | `v1/orel/delete` |
| POST | `v1/orel` | `v1/orel/create` |
| GET,HEAD | `v1/orel` | `v1/orel/index` |
| * | `v1/orel/<id:\d[\d,]*>` | `v1/orel/options` |
| * | `v1/orel` | `v1/orel/options` |
| POST | `v1/forum-theme/<id:\d+>/visit` | `v1/forum-theme/visit` |
| PATCH | `v1/forum-theme/<id:\d+>/close` | `v1/forum-theme/close` |
| DELETE | `v1/forum-theme/<id:\d+>` | `v1/forum-theme/delete` |
| OPTIONS | `v1/forum-theme/<id:\d+>/visit` | `v1/forum-theme/options` |
| GET | `v1/forum-theme/my` | `v1/forum-theme/my` |
| PUT,PATCH | `v1/forum-theme/<id:\d[\d,]*>` | `v1/forum-theme/update` |
| DELETE | `v1/forum-theme/<id:\d[\d,]*>` | `v1/forum-theme/delete` |
| GET,HEAD | `v1/forum-theme/<id:\d[\d,]*>` | `v1/forum-theme/view` |
| POST | `v1/forum-theme` | `v1/forum-theme/create` |
| GET,HEAD | `v1/forum-theme` | `v1/forum-theme/index` |
| * | `v1/forum-theme/<id:\d[\d,]*>` | `v1/forum-theme/options` |
| * | `v1/forum-theme` | `v1/forum-theme/options` |
| GET | `v1/forum-comment/my` | `v1/forum-comment/my` |
| POST | `v1/forum-comment/<id:\d+>/gift` | `v1/forum-comment/gift` |
| GET | `v1/forum-comment/<id:\d+>/gifts` | `v1/forum-comment/gifts` |
| OPTIONS | `v1/forum-comment/<id:\d+>/gift` | `v1/forum-comment/options` |
| OPTIONS | `v1/forum-comment/<id:\d+>/gifts` | `v1/forum-comment/options` |
| PUT,PATCH | `v1/forum-comment/<id:\d[\d,]*>` | `v1/forum-comment/update` |
| GET,HEAD | `v1/forum-comment/<id:\d[\d,]*>` | `v1/forum-comment/view` |
| POST | `v1/forum-comment` | `v1/forum-comment/create` |
| GET,HEAD | `v1/forum-comment` | `v1/forum-comment/index` |
| * | `v1/forum-comment/<id:\d[\d,]*>` | `v1/forum-comment/options` |
| * | `v1/forum-comment` | `v1/forum-comment/options` |
| POST | `v1/craft/command` | `v1/craft/command` |
| GET | `v1/craft/history` | `v1/craft/history` |
| OPTIONS | `v1/craft/command` | `v1/craft/options` |
| OPTIONS | `v1/craft/history` | `v1/craft/options` |
| GET,HEAD | `v1/craft` | `v1/craft/index` |
| * | `v1/craft/<id:\d[\d,]*>` | `v1/craft/options` |
| * | `v1/craft` | `v1/craft/options` |

## Места вызова на frontend

Разобрано 379 файлов JS/TS/Vue, найдено 190 мест HTTP-вызова, ошибок разбора нет. По статическому графу импортов от main.js достижимы 114 вызовов. Достижимость не доказывает фактический запуск; условные ветки и внешние клиенты требуют отдельных проверок. Динамические адреса указаны исходным выражением, их параметры определяет адаптер.

Для каждого вызова указано место в project/src. Исторические адаптеры без связи с текущим entry point сохранены отдельно от активных потребителей; их наличие не означает, что соответствующая функция доступна пользователю.

| Файл:строка | Метод | Адрес/выражение | Достижим из SPA |
| --- | --- | --- | --- |
| `components/pages/world/WorldWarehousePanel.vue:22` | GET | `url()` | нет |
| `components/pages/world/WorldWarehousePanel.vue:33` | POST | `url('-expand-preview')` | нет |
| `services/api/chat.js:30` | GET | ``${baseUrlRoom}?sort=-last_message_at&page=${page}`` | нет |
| `services/api/chat.js:31` | GET | ``${baseUrlRoom}/my?sort=-last_message_at&page=${page}`` | нет |
| `services/api/chat.js:32` | GET | ``${baseUrlRoom}/${id}`` | нет |
| `services/api/chat.js:34` | POST | `baseUrlRoom` | нет |
| `services/api/chat.js:35` | DELETE | ``${baseUrlRoom}/${id}`` | нет |
| `services/api/chat.js:36` | POST | ``${baseUrlRoom}/${id}/join`` | нет |
| `services/api/chat.js:37` | POST | ``${baseUrlRoom}/${id}/leave`` | нет |
| `services/api/chat.js:38` | GET | ``${baseUrlRoom}/${id}/members`` | нет |
| `services/api/chat.js:45` | GET | ``${baseUrlMessage}?${params.toString()}`` | нет |
| `services/api/chat.js:47` | GET | ``${baseUrlMessage}/${id}`` | нет |
| `services/api/chat.js:48` | POST | `baseUrlMessage` | нет |
| `services/api/chat.js:49` | PATCH | ``${baseUrlMessage}/${id}`` | нет |
| `services/api/chat.js:50` | DELETE | ``${baseUrlMessage}/${id}`` | нет |
| `services/api/chat.js:51` | POST | ``${baseUrlRoom}/${room_id}/read`` | нет |
| `services/api/chat.js:55` | POST | ``${baseUrlAuth}/ws-token`` | нет |
| `services/api/classicCraft.ts:85` | GET (shared) | `url()` | да |
| `services/api/classicCraft.ts:87` | POST | `url('/command')` | да |
| `services/api/classicCraft.ts:91` | GET (shared) | `url('/history')` | да |
| `services/api/community.ts:16` | GET (shared) | `config.makeApiUrl(`v1/history/${kind}-type`)` | да |
| `services/api/community.ts:21` | GET (shared) | `config.makeApiUrl(`v1/history/${kind}`)` | да |
| `services/api/community.ts:54` | GET (shared) | `config.makeApiUrl('v1/bonus/available')` | да |
| `services/api/craft.js:22` | GET | `baseUrlItem` | нет |
| `services/api/craft.js:26` | GET | `baseUrlRecipe` | нет |
| `services/api/craft.js:27` | GET | ``${baseUrlRecipe}/${id}`` | нет |
| `services/api/craft.js:31` | GET | ``${baseUrlInventory}/my`` | нет |
| `services/api/craft.js:35` | POST | ``${baseUrlRecipe}/${recipeId}/craft`` | нет |
| `services/api/craft.js:39` | GET | `baseUrlShop` | нет |
| `services/api/craft.js:40` | POST | ``${baseUrlShop}/${itemId}/buy`` | нет |
| `services/api/exchange.js:10` | GET | ``${baseUrl}v1/exchange`` | нет |
| `services/api/exchange.js:17` | GET | ``${baseUrl}v1/exchange`` | нет |
| `services/api/exchange.js:24` | GET | ``${baseUrl}v1/exchange/my`` | нет |
| `services/api/exchange.js:31` | GET | ``${baseUrl}v1/exchange/my`` | нет |
| `services/api/exchange.js:38` | GET | ``${baseUrl}v1/exchange/history`` | нет |
| `services/api/exchange.js:49` | POST | ``${baseUrl}v1/exchange`` | нет |
| `services/api/exchange.js:56` | PUT | ``${baseUrl}v1/exchange/${id}`` | нет |
| `services/api/exchange.js:63` | DELETE | ``${baseUrl}v1/exchange/${id}`` | нет |
| `services/api/fiveGame.ts:47` | GET | `url(`/${id}`)` | да |
| `services/api/fiveGame.ts:49` | POST | `url()` | да |
| `services/api/fiveGame.ts:58` | POST | `url(`/play/${game.id}`)` | да |
| `services/api/fiveGame.ts:65` | DELETE | `url(`/${id}`)` | да |
| `services/api/forum.js:11` | GET | ``${baseUrlTheme}/my?sort=-last_comment_created_at&page=${page}`` | нет |
| `services/api/forum.js:26` | GET | ``${baseUrlTheme}?sort=-last_comment_created_at&page=${page}`` | нет |
| `services/api/forum.js:39` | POST | ``${baseUrlTheme}`` | нет |
| `services/api/forum.js:51` | PUT | ``${baseUrlTheme}/${id}`` | нет |
| `services/api/forum.js:63` | GET | ``${baseUrlTheme}/${id}`` | нет |
| `services/api/forum.js:75` | DELETE | ``${baseUrlTheme}/${id}`` | нет |
| `services/api/forum.js:89` | GET | ``${baseUrlComment}/my?sort=-id&page=${page}`` | нет |
| `services/api/forum.js:104` | GET | ``${baseUrlComment}?filter[theme_id]=${theme_id}&expand=user&page=${page}`` | нет |
| `services/api/forum.js:116` | POST | ``${baseUrlComment}`` | нет |
| `services/api/forum.js:128` | PUT | ``${baseUrlComment}/${id}`` | нет |
| `services/api/forum.js:140` | GET | ``${baseUrlComment}/${id}`` | нет |
| `services/api/forum.js:152` | DELETE | ``${baseUrlComment}/${id}`` | нет |
| `services/api/gameHistory.ts:18` | GET (shared) | `config.makeApiUrl(`v1/${kind}/history-kons`)` | да |
| `services/api/gameHistory.ts:22` | GET (shared) | `config.makeApiUrl(`v1/${kind}/stakes`)` | да |
| `services/api/gameOverview.ts:42` | GET (shared) | `config.makeApiUrl(`v1/${kind}/summary`)` | да |
| `services/api/games/duel.js:16` | GET | ``${baseUrlDuel}`` | да |
| `services/api/games/duel.js:31` | GET | ``${baseUrlDuel}/my`` | да |
| `services/api/games/duel.js:49` | GET | ``${baseUrlDuel}/history?page=${page}`` | да |
| `services/api/games/duel.js:67` | POST | ``${baseUrlDuel}`` | да |
| `services/api/games/duel.js:82` | POST | ``${baseUrlDuel}/play/${id}`` | да |
| `services/api/games/duel.js:97` | DELETE | ``${baseUrlDuel}/${id}`` | да |
| `services/api/games/five.js:16` | GET | ``${baseUrlFive}`` | нет |
| `services/api/games/five.js:31` | GET | ``${baseUrlFive}/my?page=${page}`` | нет |
| `services/api/games/five.js:49` | GET | ``${baseUrlFive}/history?page=${page}`` | нет |
| `services/api/games/five.js:67` | POST | ``${baseUrlFive}`` | нет |
| `services/api/games/five.js:82` | POST | ``${baseUrlFive}/play/${id}`` | нет |
| `services/api/games/five.js:97` | DELETE | ``${baseUrlFive}/${id}`` | нет |
| `services/api/games/orel.js:10` | GET | ``${baseUrlOrel}/my?page=${page}`` | нет |
| `services/api/games/orel.js:26` | GET | ``${baseUrlOrel}?${urlParams}`` | нет |
| `services/api/games/orel.js:38` | GET | ``${baseUrlOrel}/kon-count`` | нет |
| `services/api/games/orel.js:59` | POST | ``${baseUrlOrel}`` | нет |
| `services/api/games/orel.js:71` | POST | ``${baseUrlOrel}/play/${id}`` | нет |
| `services/api/games/orel.js:85` | DELETE | ``${baseUrlOrel}/${id}`` | нет |
| `services/api/games/orel.js:97` | GET | ``${baseUrlOrel}/history?page=${page}`` | нет |
| `services/api/games/saper.js:11` | GET | ``${baseUrlSaper}/my?page=${page}`` | нет |
| `services/api/games/saper.js:27` | GET | ``${baseUrlSaper}?${urlParams}`` | нет |
| `services/api/games/saper.js:39` | GET | ``${baseUrlSaper}/kon-count`` | нет |
| `services/api/games/saper.js:60` | POST | ``${baseUrlSaper}`` | нет |
| `services/api/games/saper.js:85` | POST | ``${baseUrlSaper}/play/${id}`` | нет |
| `services/api/games/saper.js:98` | DELETE | ``${baseUrlSaper}/${id}`` | нет |
| `services/api/games/saper.js:110` | GET | ``${baseUrlSaper}/history?page=${page}`` | нет |
| `services/api/header.ts:15` | GET | `url('users/online-count')` | да |
| `services/api/header.ts:18` | POST | `url('users/heartbeat')` | да |
| `services/api/header.ts:27` | GET | `url('tips/random')` | да |
| `services/api/homeActivity.ts:18` | GET (shared) | `config.makeApiUrl(`v1/bonus/${authenticated ? 'my-fund' : 'fund'}`)` | да |
| `services/api/homeGames.ts:6` | GET (shared) | `config.makeApiUrl('v1/stat/recent-games')` | да |
| `services/api/portal.ts:50` | GET (shared) | `url(path)` | да |
| `services/api/portal.ts:54` | GET (shared) | `url(path)` | да |
| `services/api/portal.ts:57` | GET (shared) | `url('users/online')` | да |
| `services/api/portal.ts:71` | REQUEST | `{ url: url(path), method, data }` | да |
| `services/api/portal.ts:74` | POST | `url(`saper/start/${id}`)` | да |
| `services/api/portal.ts:77` | POST | `url(`${kind}/everyday`)` | да |
| `services/api/simpleWorld.ts:55` | GET (shared) | `url('objects')` | да |
| `services/api/simpleWorld.ts:56` | GET (shared) | `url(`objects/${id}`)` | да |
| `services/api/simpleWorld.ts:57` | GET (shared) | `url('builds')` | да |
| `services/api/simpleWorld.ts:72` | GET (shared) | `url('templates')` | да |
| `services/api/simpleWorld.ts:83` | POST | `url(path)` | да |
| `services/api/simpleWorld.ts:96` | GET (shared) | `url('start')` | да |
| `services/api/simpleWorld.ts:100` | GET (shared) | `url('builds/active')` | да |
| `services/api/simpleWorld.ts:104` | GET (shared) | `url(`objects/${id}/gather`)` | да |
| `services/api/simpleWorld.ts:120` | GET (shared) | `url(`objects/${id}/budget`)` | да |
| `services/api/stat.js:14` | GET | ``${baseUrl}v1/stat`` | нет |
| `services/api/stat.js:29` | GET | ``${baseUrl}v1/stat/user/${login}`` | нет |
| `services/api/stat.js:44` | GET | ``${baseUrl}v1/stat/top?period=${period}`` | нет |
| `services/api/statistics.ts:67` | GET (shared) | `config.makeApiUrl('v1/stat')` | да |
| `services/api/world.ts:161` | GET (shared) | `url(path)` | да |
| `services/api/world.ts:163` | GET (shared) | `url('')` | да |
| `services/api/world.ts:164` | GET (shared) | `url(`/nodes/${integer(id, 1)}/navigation`)` | да |
| `services/api/world.ts:165` | GET (shared) | `url(`/nodes/${integer(id, 1)}/children`)` | да |
| `services/api/world.ts:184` | GET (shared) | `url(`/nodes/${nodeId}/map`)` | да |
| `services/api/world.ts:196` | GET (shared) | `url('/professions')` | да |
| `services/api/world.ts:202` | POST | `url(`/professions/${integer(id, 1)}/${action}-preview`)` | да |
| `services/api/world.ts:205` | POST | `url(`/nodes/${integer(id, 1)}/map-${action}-preview`)` | да |
| `services/api/world.ts:209` | POST | `url(`/nodes/${integer(id, 1)}/map-${action}-preview`)` | да |
| `services/api/world.ts:212` | GET (shared) | `url(`/nodes/${integer(id, 1)}/statistics`)` | да |
| `services/api/world.ts:220` | POST | `url(`/nodes/${integer(id, 1)}/${action}-preview`)` | да |
| `services/api/world.ts:223` | POST | `url(path)` | да |
| `services/api/world.ts:227` | GET (shared) | `url('/onboarding')` | да |
| `services/api/world.ts:230` | POST | `url('/onboarding/preview')` | да |
| `services/api/worldBuildingOperation.ts:12` | GET (shared) | `url(node)` | нет |
| `services/api/worldBuildingOperation.ts:25` | POST | `url(node, `building-${action}-preview`)` | нет |
| `services/api/worldBuildingRepair.ts:31` | GET (shared) | `url(node)` | да |
| `services/api/worldBuildingRepair.ts:40` | POST | `url(node, 'building-repair-preview')` | да |
| `services/api/worldCampsite.ts:12` | GET (shared) | `url(node, 'campsite')` | нет |
| `services/api/worldCampsite.ts:23` | POST | `url(node, `supplies-${action}-preview`)` | нет |
| `services/api/worldCharacter.ts:8` | GET (shared) | `config.makeApiUrl('v1/world/economy')` | да |
| `services/api/worldCharacter.ts:33` | GET (shared) | `config.makeApiUrl('v1/world/economy/progression')` | да |
| `services/api/worldConstruction.ts:37` | GET (shared) | `url(node)` | да |
| `services/api/worldConstruction.ts:56` | POST | `url(node, `construction-${action}-preview`)` | да |
| `services/api/worldCultivation.ts:18` | GET (shared) | `config.makeApiUrl(`v1/world/beds/${integer(bed, 1)}/cultivation`)` | да |
| `services/api/worldCultivation.ts:20` | GET (shared) | `config.makeApiUrl('v1/world/crops')` | да |
| `services/api/worldCultivation.ts:30` | POST | `config.makeApiUrl(`v1/world/beds/${integer(bed, 1)}/${action}-preview`)` | да |
| `services/api/worldDemolition.ts:16` | GET (shared) | `url(node)` | нет |
| `services/api/worldDemolition.ts:25` | POST | `url(node, 'demolish-preview')` | нет |
| `services/api/worldDemolition.ts:33` | GET (shared) | `url(node, 'demolitions')` | нет |
| `services/api/worldEconomy.ts:21` | GET (shared) | `url(node, 'economy')` | да |
| `services/api/worldEconomy.ts:33` | GET (shared) | `url(node, 'obligations')` | да |
| `services/api/worldEconomy.ts:48` | POST | `url(node, 'collect-preview')` | да |
| `services/api/worldEconomy.ts:58` | POST | `url(node, 'budget-grant-preview')` | да |
| `services/api/worldEconomy.ts:64` | POST | `url(node, 'pay-preview')` | да |
| `services/api/worldEconomy.ts:76` | POST | `url(node, 'finance-policy-preview')` | да |
| `services/api/worldEconomy.ts:83` | POST | `url(node, 'invest-preview')` | да |
| `services/api/worldEconomyHistory.ts:52` | GET (shared) | `config.makeApiUrl('v1/world/economy/history')` | да |
| `services/api/worldEconomyStorage.ts:18` | GET (shared) | `url(id)` | да |
| `services/api/worldEconomyStorage.ts:19` | POST | `url(id, '/expand-preview')` | да |
| `services/api/worldEquipmentExpansion.ts:15` | GET (shared) | `url(node, 'equipment-expansion')` | да |
| `services/api/worldEquipmentExpansion.ts:32` | POST | `url(node, 'equipment-expand-preview')` | да |
| `services/api/worldEquipmentWear.ts:14` | GET (shared) | `config.makeApiUrl(`v1/world/equipment/${id(instance)}/wear`)` | да |
| `services/api/worldFinanceReport.ts:28` | GET (shared) | `config.makeApiUrl(`v1/world/nodes/${id(node)}/finance-report`)` | да |
| `services/api/worldGarden.ts:29` | GET (shared) | `url(node)` | да |
| `services/api/worldGarden.ts:51` | POST | `url(node, `garden-${action}-preview`)` | да |
| `services/api/worldHarvest.ts:16` | GET (shared) | `url(node)` | да |
| `services/api/worldHarvest.ts:30` | POST | `url(node, `harvest-${action}-preview`)` | да |
| `services/api/worldHousing.ts:16` | GET (shared) | `url(node, 'housing')` | да |
| `services/api/worldHousing.ts:31` | POST | `url(node, `housing-${action}-preview`)` | да |
| `services/api/worldKitchen.ts:19` | GET (shared) | `config.makeApiUrl(`v1/world/economy/kitchen/${node}`)` | да |
| `services/api/worldMining.ts:68` | GET (shared) | `config.makeApiUrl(`v1/world/objects/${id}/mining`)` | да |
| `services/api/worldMvp.ts:56` | GET (shared) | `config.makeApiUrl('v1/world/economy')` | да |
| `services/api/worldNights.ts:23` | GET (shared) | `config.makeApiUrl(`v1/world/nodes/${node}/nights`)` | да |
| `services/api/worldOrders.ts:19` | GET (shared) | `url(node, 'orders')` | да |
| `services/api/worldOrders.ts:34` | GET (shared) | `url(node, 'order-items')` | да |
| `services/api/worldOrders.ts:37` | GET (shared) | `url(node, 'order-stock')` | да |
| `services/api/worldOrders.ts:56` | POST | `url(node, `order-${action}-preview`)` | да |
| `services/api/worldPremises.ts:31` | GET (shared) | `url(node)` | да |
| `services/api/worldPremises.ts:50` | POST | `url(node, `premises-${action}-preview`)` | да |
| `services/api/worldPresence.ts:12` | GET (shared) | `url(node)` | да |
| `services/api/worldPresence.ts:20` | POST | `url(node)` | да |
| `services/api/worldRepairContracts.ts:18` | GET (shared) | `url(node)` | нет |
| `services/api/worldRepairContracts.ts:39` | POST | `url(node, 'repair-contract-preview')` | нет |
| `services/api/worldShelter.ts:34` | GET (shared) | `url(node)` | да |
| `services/api/worldShelter.ts:52` | POST | `url(node, `shelter-${action}-preview`)` | да |
| `services/api/worldStorage.ts:39` | GET (shared) | `url('storages')` | да |
| `services/api/worldStorage.ts:40` | GET (shared) | `url(`storage/${id(storage)}`)` | да |
| `services/api/worldStorage.ts:54` | POST | `url('storage/transfer-preview')` | да |
| `services/api/worldStorage.ts:56` | GET (shared) | `url(`nodes/${id(node)}/placement-options`)` | да |
| `services/api/worldStorage.ts:66` | POST | `url('storage/chest-repair-preview')` | да |
| `services/api/worldStorage.ts:70` | GET (shared) | `url('storage/recovery')` | да |
| `services/api/worldStorage.ts:78` | POST | `url('storage/recovery-preview')` | да |
| `services/api/worldTreasuryReceipts.ts:39` | GET (shared) | `config.makeApiUrl(`v1/world/nodes/${id(node)}/treasury-receipts`)` | да |
| `services/api/worldWorkspace.ts:42` | GET (shared) | `url('')` | да |
| `services/api/worldWorkspace.ts:43` | POST | `url('/preview')` | да |
| `services/api.js:10` | POST | ``${baseUrl}login`` | да |
| `services/api.js:22` | GET | ``${baseUrl}login-key/${key}`` | да |
| `services/api.js:34` | POST | ``${baseUrl}registration`` | да |
| `services/api.js:46` | POST | ``${baseUrl}logout`` | да |
| `services/api.js:58` | GET | ``${baseUrl}v1/users/wall/${username}`` | да |
| `services/api.js:73` | PATCH | ``${baseUrl}v1/users/wall`` | да |
| `services/api.js:85` | GET | ``${baseUrl}v1/users/profile`` | да |
