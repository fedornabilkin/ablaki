# Документация backend

## Разделы

| Папка | Содержание |
| --- | --- |
| [tz](tz/README.md) | Исходные технические задания: мир, чат, реферальная программа. |
| [plan](plan/) | Активные планы реализации; завершённые планы — в [plan/done](plan/done/). |
| [architecture](architecture/) | Архитектура мира, правила игровой экономики и актуальные контракты API. |
| [operations](operations/) | Выкладка, запуск, перенос данных, эксплуатационные команды и [Insomnia](operations/insomnia.md). |
| [reports](reports/) | Сохранённые итоговые отчёты об интеграции и выпусках. |
| [mockup](mockup/) | HTML-макеты интерфейса. |

## Основные документы

- [MVP: подготовка к закрытому тестированию](plan/2026-10-15-world-closed-test.md) — очередь по [дополнительному ТЗ](tz/mvp-world.md): [баланс](plan/2026-10-15-world-economy-balance.md), [сервер/БД](plan/2026-10-15-world-economy-integrity.md), [приёмка](plan/2026-10-15-world-closed-test-acceptance.md) и [интерфейс](../../frontend/docs/plan/2026-10-15-world-closed-test-ui.md). Банк не нужен, переводы кредитов уже реализованы; остаются их регрессия и интеграция учёта. Оплата и пополнение не предусмотрены.
- Первый этап по [economy.md](tz/economy.md): [CONTENT — новый контент экономики](plan/2026-10-16-world-economy-content.md) и [CONUI — его интерфейс](../../frontend/docs/plan/2026-10-16-world-economy-content-ui.md). Расхождения чисел разрешаются до изменения тарифов; целевой расчёт старта — 250 Cr, мгновенный крафт сохраняется.
- Второй этап по [bazar.md](tz/bazar.md): [MKT — NPC и P2P](plan/2026-10-16-world-market.md), [MKTOP — управление, защита и допуск](plan/2026-10-16-world-market-operations.md), [MUI — интерфейс](../../frontend/docs/plan/2026-10-16-world-market-ui.md). Сначала городской магазин, затем P2P после наблюдения NPC.

- [Простая модель мира](architecture/simple-world.md), [новый API](architecture/simple-world-api.md), [порядок установки](operations/simple-world-install.md).

- [Архитектура мира](architecture/world-architecture.md) и [игровая экономика](architecture/world-gameplay-economy.md).
- Планы [backend](plan/2026-09-26-world-backend.md) и [БД](plan/2026-09-26-world-database.md).
- [Контракт списков SPA](architecture/frontend-list-api.md), [инструкция Insomnia](operations/insomnia.md), [коллекция запросов](../Insomnia.json).
- [Развёртывание backend](operations/production-test-deployment.md) и [автоматическая подготовка тестового мира](operations/world-test-autostart.md).
- [Документация Vue frontend](../../frontend/docs/README.md).

## Куда сохранять новые файлы

ТЗ остаются в `docs/tz`; актуальные правила реализации и контракты относятся к `docs/architecture`, эксплуатационные инструкции — к `docs/operations`. Отдельные отчёты по этапам разработки не дублируются: история изменений доступна в Git и завершённых планах. При переносе документа обновляются относительные ссылки, в том числе из соседнего frontend.

Новые результаты проверок и тестирования — логи, скриншоты, выгрузки, временные БД, ответы API и вспомогательные скрипты — сохраняются в общей рабочей папке `../temp/<задача>/` относительно `back`, вне Git. Существующие исторические отчёты в `docs/reports` сохранены как документация. `docs/temp/DB` содержит прежние локальные дампы и не является частью публикуемой документации.
