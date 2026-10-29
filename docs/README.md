# Документация backend

Актуальная очередь и состояние работ: **[планы](plan/README.md)**. Ближайший шаг — ручная приёмка двух участников в подготовленном локальном мире, затем отдельное первое включение production.

| Раздел | Содержание |
| --- | --- |
| [tz](tz/README.md) | Исходные требования, экономика и последующее развитие. |
| [plan](plan/README.md) | Активные задачи; [done](plan/done/) — завершённые пакеты. |
| [architecture](architecture/) | Модель мира, экономика и API-контракты. |
| [operations](operations/) | Установка, эксплуатация, выпуск и [Insomnia](operations/insomnia.md). |
| [reports](reports/) | Итоговые отчёты. |
| [mockup](mockup/) | HTML-макеты. |

## Текущий MVP

- [Что осталось до запуска](plan/2026-10-21-mvp-launch-readiness.md) и [приёмка ACC](plan/2026-10-15-world-closed-test-acceptance.md).
- [Чистый локальный мир 29 октября](operations/local-world-reset-2026-10-29.md): два места по 250, награды/заказы, NPC, время 1:1; аккаунты и деньги сохранены.
- [Чеклист приёмки](../../frontend/docs/operations/world-mvp-checklist.md): автоматические/ручные результаты, комментарии и экспорт из браузера.
- [Аудит test/production 29 октября](operations/world-production-readiness-2026-10-29.md): PHP 8.3.36 на production подтверждён; новый мир там ещё требует отдельной подготовки.
- [Ускоренная выкладка](operations/fast-test-release-2026-10-26.md) внедрена; [серия замеров](plan/2026-10-19-test-deployment-optimization.md) остаётся открытой.

## Разработка и контракты

- [Общая очередь мира](plan/2026-10-15-world-closed-test.md), [дорожная карта backend](plan/2026-09-26-world-backend.md) и [БД](plan/2026-09-26-world-database.md).
- [API простой модели](architecture/simple-world-api.md), [экономика](architecture/economy-runtime-api.md), [инструкция выкладки](operations/production-test-deployment.md).
- [Рынок](plan/2026-10-16-world-market.md) и [эксплуатация рынка](plan/2026-10-16-world-market-operations.md) — следующая очередь.
- [Итог актуализации планов](plan/done/2026-10-29-plan-refresh.md).

Временные проверки, логи и резервные копии хранятся в общей `../temp/<задача>/`, вне Git. Изменения планов не являются разрешением на production-выкладку.
