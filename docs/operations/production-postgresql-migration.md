# Online-переход нового сайта на PostgreSQL и музей на MariaDB

Актуальное решение от 2026-11-17. [Задачи реализации](../plan/2026-11-17-production-postgresql.md). Предыдущий [offline-вариант](production-postgresql-offline-reference.md) сохранён только как справочный; его остановку сайтов/глобальный lock backup нельзя смешивать с этим алгоритмом.

**Статус:** проект инструкции, не готовый серверный скрипт. Production не обследован; CDC, очередь, fencing и `deploy/db-online` ещё не реализованы. Команды этого интерфейса ниже — контракт задач F1 и связанных задач, а не существующий executable. Их необходимо реализовать и проверить на копии до production. Публикация, изменение прав и переключение в рамках подготовки документа не выполняются.

## Результат и смысл «без даунтайма»

Старый сайт до переноса получает только разрешённые SELECT из MariaDB. Новый продолжает работать с MariaDB, пока snapshot+CDC наполняет PostgreSQL. На короткой границе новые изменения сохраняются в независимую надёжную очередь, чтение продолжается. После последнего старого commit и догоняющей репликации запись переключается на PostgreSQL. MariaDB остаётся историческим снимком музея.

Новый сайт не показывает maintenance/503, процессы чтения и приёма команд доступны. Во время HOLD запись может ждать дольше; успех возвращается только после business commit. Если ожидание превышает синхронный timeout, нужен заранее внедрённый API 202 + operation_id/status polling. Его должны понимать старые вкладки и мобильные клиенты. Очередь без адаптации клиентов не обеспечивает прозрачный переход.

Согласованные SLO/максимальный HOLD/ёмкость очереди фиксируются по репетиции. Обещание отсутствия ошибок при произвольном долгом отказе невозможно: для превышения бюджетов до переключения предусмотрен abort на MariaDB, с выполнением уже принятых команд.

## Обозначения

- **S** — binlog/GTID checkpoint, атомарно связанный с исходным консистентным snapshot.
- **C** — одинаковый checkpoint исходных данных и PostgreSQL для предварительной полной сверки. Проверка живой MariaDB против отстающего target некорректна.
- **W** — последний commit MariaDB после закрытия dispatch и drain/fencing всех писателей. Это точная позиция транзакций, не только timestamp.
- **epoch** — монотонное поколение владельца записи в независимом state store. Старое поколение не может возобновить dispatch.
- **P1** — первый новый бизнес-commit в PostgreSQL, включая smoke/логин/worker. CDC-загрузка PostgreSQL до P1 сама по себе не запрещает отмену переключения.

## 1. Проверка осуществимости и серверная инвентаризация

Из репозитория известны backend `/var/www/api.ablakin.ru`, PostgreSQL 16 в CI и историческая MariaDB 10.1.48. Это не заменяет проверку сервера. Инвентаризация должна установить:

- Реальные DB instances/схемы, Docker/project/networks/порты, роли и локальные overrides каждого web/CLI приложения. MYSQL_DB_* сейчас имеют приоритет над PG_DB_*; PG_DB_PORT не включён в текущий DSN.
- Все писатели: PHP старого сайта, новый API/admin, mutating GET/login/presence, cron/systemd timers, world-worker, бот на другом сервере, callbacks, ручные CLI и MariaDB events/triggers. Сторонние базы/приложения на том же сервере нельзя отключать глобальной командой.
- Binlog, ROW/full row images, GTID/retention, движки и PK, DDL, charset/collation, большие UNSIGNED, zero dates, precision и объёмы. Snapshot должен соответствовать checkpoint S, а log должен храниться до подтверждённого apply.
- Поддержку конкретной старой MariaDB выбранным CDC. Название инструмента и версия фиксируются только после proof-of-concept. Нельзя предполагать, что современный коннектор поддерживает MariaDB 10.1.

Если binlog отсутствует и требует restart, либо snapshot на MyISAM потребует длительной блокировки писателей, сначала спроектировать replica bootstrap/failover или проверенное online-преобразование движков. Нельзя объявлять подготовку «нулевым простоем», незаметно остановив единственный источник. `mysqldump --single-transaction` не решает согласованность MyISAM. `pgloader` сам по себе не является CDC.

## 2. Музей до начала копирования

1. Создать отдельную роль с SELECT на согласованный публичный allowlist. Grant на всю БД раскрывает user.password_hash/auth_key/Telegram/private messages и не подходит по умолчанию. Views с DEFINER и функции проверяются отдельно; никаких EXECUTE/FILE/GRANT/DML/DDL.
2. Перевести все подключения музея на эту роль; убрать старые write/admin credentials, session DB writes, cron, payments, SMTP/API секреты, mutating routes и GET side effects. Запретить автоматические рестарты старых писателей. Музей по умолчанию без входа, публичный.
3. Выделить cookies/домен, запретить доступ музея к внутренним write API и внешним побочным действиям. Проверить runtime filesystem/logging policy: обязательный HTTP access log инфраструктуры допустим, изменение бизнес-файлов/отправка сообщений — нет.
4. Пройти маршруты под реальными SELECT-only правами и попытки обхода через stored routines/views. Настроить лимиты чтения/соединений и сетевую изоляцию. До cutover музей видит меняющуюся MariaDB; после — фиксированный срез W с явной подписью и ссылкой на новый сайт.

Конкретные GRANT/REVOKE генерируются после inventory и ревью; глобальный `REVOKE` или выдача SELECT ON *.* из шаблона недопустимы. После фиксации музея его учётная запись не участвует в отключении писателей и продолжает читать.

## 3. Совместимый runtime и единая запись

До миграции выпустить совместимый backend/frontend, работающие на MariaDB, с PostgreSQL-веткой и контролируемым dispatch:

- Независимая durable очередь/state store, переживающая отказ обоих backend pools. Queue ack после durable persistence; бизнес-успех после commit. Один idempotency key связан с пользователем/типом/payload hash и сохранённым результатом. Сбой между commit и ack не должен повторять операцию.
- Проверка прав/остатков/дедлайнов при исполнении, а не только при enqueue; срок действия сессии и отмена операции имеют явную политику. Порядок зависимых команд сохраняется; один общий total order всех пользователей не требуется, но межсчётные операции атомарны.
- State machine MARIADB→HOLD→POSTGRES; общий lease и epoch, а не локальный файл на одном VPS. Все HTTP/background writers подчиняются режиму. Старая версия процесса и старое соединение должны быть fenced и на уровне DB-доступа.
- Две группы backend с прогретыми соединениями и раздельным namespace cache. Чтение до переключения остаётся на MariaDB; после подтверждения PG-write нельзя случайно читать старую MariaDB. Клиент видит pending для неисполненных команд.
- Startup FPM без старого автоматического init/migrate/seed/cron. Постоянный worker/бот включается отдельно. Текущие MySQL-only release/inspect/GET_LOCK guards заменяются, а строгие identity/pending/financial lease проверки сохраняются.

Инструменты shadow/smoke не должны воспроизводить платежи/подарки/переводы на production как «тестовую нагрузку». Проверки записи на репетиции идут в изолированной копии; production smoke — заранее согласованные операции с полным аудитом и без удаления следов.

## 4. Snapshot, CDC и предварительная сверка

1. Подготовить пустую PostgreSQL с canonical DDL/mapping. Owner/importer/runtime роли раздельны, SCRAM, UTF-8/locale/timezone/backup/WAL настроены. Приложение пока не имеет write-доступа к ней.
2. Получить consistent snapshot S с непрерывным binlog capture. Источник в это время продолжает обслуживать сайты. Пауза метаданных, если необходима для координат, должна укладываться в согласованный бюджет и покрываться работающим приёмом команд.
3. Загрузить snapshot с точными IDs/bytes/decimal; хранить INSERT/UPDATE/DELETE после S и применять транзакционно. Checkpoint apply сохраняется после commit. Повтор событий идемпотентен; lost binlog/reject rows — остановка перехода, а не пропуск.
4. Сохранить значения и данные migration history, storage/wallet/economy profile, очередей/idempotency/outbox. Не запускать повторный test-ready/NPC funding/backfill. Views/triggers/functions переносятся по mapping, не как обычные таблицы.
5. На общем checkpoint C сравнить все строки/PK/мультимножества без PK/канонические hashes, владельцев денег/резервов/обязательств, double-entry, вещи/стройки, user/token bytes, FK/unique/views и job состояния. Разрешённые преобразования перечислены, суммы без индивидуальной сверки недостаточны.
6. Накапливать точный список изменённых с C ключей/удалений, включая эффекты triggers. Во время финального HOLD повторно сверить этот набор и глобальные инварианты на W. Если доказательства полноты списка нет — нужна полная сверка, переключение откладывается, а не растягивает HOLD бесконечно.
7. Включить shadow reads без побочных действий. Нормализовать ожидаемые collation/порядок различия; учитывать lag, не выдавать target-ответы пользователям. Проверить производительность на пиковой нагрузке.

Тяжёлые индексы/constraint validation/ANALYZE и прогрев выполняются до HOLD. На W остаются измеренные tail verification, final sequence correction и проверки constraints/defaults. Без validated constraints/индексов открывать PostgreSQL нельзя. Sequence не обновляются автоматически от CDC INSERT явных IDs: после остановки importer и до PG business writes привести каждую связанную sequence к max(id), корректно обработать пустые таблицы.

## 5. Контракт будущего `deploy/db-online`

**Ни одной из следующих команд сейчас нет в репозитории.** F1 реализует CLI, secure config и контракты; после репетиции команды становятся точной серверной инструкцией. Каждая команда обязана проверять source/target identities, SHA/mapping/operation ID, общее состояние/epoch и возвращать ненулевой exit при неполной проверке. Повтор должен возобновлять ту же операцию, а не повторять destructive bootstrap.

| Команда | Проверяемый результат |
|---|---|
| `inventory` | Реальные consumers, schema, binlog/движки/ресурсы, compatibility report без секретов |
| `museum prepare / verify` | Reviewed least-privilege роли/конфиги, отсутствие side effects, доступность публичных страниц |
| `preflight` | Поддерживаемый CDC, snapshot protocol, restore/rehearsal, queue/SLO/capacity и совместимый release готовы |
| `target prepare` | Отдельная пустая PostgreSQL, canonical schema/roles/backup, отказ при непустой цели |
| `replication start / status` | Snapshot S, durable capture/apply checkpoints, lag/retention/rejects; без DROP/seed |
| `verify full / tail` | Сверка на C или W с отчётом каждого объекта; tail включает все изменённые ключи/удаления с C |
| `shadow verify` | Read-only сравнение без внешних действий, норматив по расхождениям/задержке |
| `cutover hold` | Атомарный HOLD/epoch, enqueue доступен, Maria dispatch закрыт, возврат операции/state ID |
| `writers drain-fence` | Все Maria business transactions закончены, old runtime credentials/sessions/events fenced, музей/CDC сохраняют read доступ; финальный W записан только после доказательства |
| `replication await-final` | Target apply=W, нет незавершённой транзакции, importer остановлен/опечатан на W |
| `target finalize` | Constraints/defaults/sequences/гранты/кэши/read pools готовы в пределах HOLD SLO |
| `cutover commit` | CAS HOLD→POSTGRES нового epoch, reads переключены, новая очередь исполняется в PG; P1 и operation outcomes фиксируются |
| `workers resume-staged` | По одному consumer с новым соединением/epoch, без повторного polling/funding и потери offsets |
| `replication seal / museum seal` | Нет позднего source commit после W; CDC закрыт с manifest, дата музейного среза зафиксирована |
| `cutover abort` | Только до P1: остановить PG admission, доказать отсутствие PG business commits, вернуть новый epoch Maria и выполнить очередь там без потери/двойного dispatch |
| `observe / backup verify-restore` | HTTP/queue/locks/деньги/ресурсы и проверенное восстановление отдельной PostgreSQL/очереди |

Конфиг, роли/секреты, проверенные образы и inventory хранятся вне Git в закрытых каталогах. URI/пароли не передавать в argv, не печатать env/полный docker inspect/Compose config. Snapshot/binlog/rejects/отчёты содержат персональные данные и защищаются отдельно.

## 6. Последовательность будущих серверных команд

Выполняет оператор только после закрытия A–F плана. Путь checkout взят из репозитория, фактические identity подтверждает inventory. CONFIG не содержит паролей; secure bindings подготовлены отдельно. Не выполнять `make init`, `down -v`, исторические world-prepare/activate или старую offline-инструкцию.

```bash
set -Eeuo pipefail
umask 077
cd /var/www/api.ablakin.ru
CONFIG=/etc/ablaki-db-online/production.yaml
RUN_DIR="/var/backups/ablaki-db-online/$(date -u +%Y%m%dT%H%M%SZ)"
sudo install -d -m 0700 -o "$(id -un)" "$RUN_DIR"
test -x deploy/db-online
# CLI дополнительно получает общий распределённый lease/epoch;
# этот локальный lock только исключает второго оператора на данном сервере.
# Файл создан заранее, inode не заменяется в ходе операции.
test -f /var/lock/ablaki-db-online.lock
test ! -L /var/lock/ablaki-db-online.lock
exec 8</var/lock/ablaki-db-online.lock
flock -n 8
./deploy/db-online inventory --config "$CONFIG" --out "$RUN_DIR/inventory"
./deploy/db-online museum prepare --config "$CONFIG" --out "$RUN_DIR/museum"
./deploy/db-online museum verify --config "$CONFIG" --out "$RUN_DIR/museum-verify"
./deploy/db-online preflight --config "$CONFIG" --out "$RUN_DIR/preflight"
./deploy/db-online target prepare --config "$CONFIG" --out "$RUN_DIR/target"
./deploy/db-online replication start --config "$CONFIG" --out "$RUN_DIR/replication"
./deploy/db-online replication status --config "$CONFIG" --out "$RUN_DIR/cdc-status"
./deploy/db-online verify full --config "$CONFIG" --out "$RUN_DIR/full"
./deploy/db-online shadow verify --config "$CONFIG" --out "$RUN_DIR/shadow"
```

`replication start` не блокирует shell до cutover; сервис сохраняет состояние/checkpoints независимо от SSH. Оператор ждёт успешного завершения snapshot и статуса ready из CLI. Если его нет, следующие команды отказывают. Новые сайты продолжают работать с MariaDB. Запретить DDL/autodeploy во время проверки, но не пользовательские изменения. Перед HOLD проверить внешний HTTP, очередь, текущий lag, доступность обоих read pools и срок binlog.

```bash
./deploy/db-online cutover hold --config "$CONFIG" --out "$RUN_DIR/hold"
./deploy/db-online writers drain-fence --config "$CONFIG" --out "$RUN_DIR/watermark-W"
./deploy/db-online replication await-final --config "$CONFIG" --out "$RUN_DIR/at-W"
./deploy/db-online verify tail --config "$CONFIG" --out "$RUN_DIR/tail"
./deploy/db-online target finalize --config "$CONFIG" --out "$RUN_DIR/finalize"
./deploy/db-online cutover commit --config "$CONFIG" --out "$RUN_DIR/commit"
./deploy/db-online workers resume-staged --config "$CONFIG" --out "$RUN_DIR/workers"
./deploy/db-online replication seal --config "$CONFIG" --out "$RUN_DIR/cdc-sealed"
./deploy/db-online museum seal --config "$CONFIG" --out "$RUN_DIR/museum-sealed"
./deploy/db-online observe --config "$CONFIG" --out "$RUN_DIR/observe"
./deploy/db-online backup verify-restore --config "$CONFIG" --out "$RUN_DIR/restore"
```

В HOLD новый сайт не останавливается: чтение работает на MariaDB, изменения durable enqueue. После drain существующие клиентские чтения могут закончиться на source; новые после PG commit должны быть направлены в PostgreSQL. Connection pooling/cache и длительные запросы участвуют в switch. Финальный W нельзя снимать, пока старые транзакции способны commit позже. Простое изменение nginx upstream или `.env` этого не обеспечивает.

### Ошибка до P1

```bash
./deploy/db-online cutover abort --config "$CONFIG" --out "$RUN_DIR/abort"
```

Abort возвращает dispatch в MariaDB под **новым** epoch и выполняет всю принятую очередь. Сначала проверяется отсутствие любого PG business commit; при неизвестном результате commit команда отказывает в небезопасном возврате. PostgreSQL остаётся изолированным; CDC возобновляется только по проверенному checkpoint, если планируется новая попытка. Никакой автоматический trap не должен открывать второй источник записи.

### Ошибка после P1

Нельзя вернуть новый сайт в замороженную MariaDB: там отсутствуют новые регистрации/переводы/игровые операции. Основной путь — fix-forward или PostgreSQL restore+WAL с согласованным RPO, сохранением queue/state store и reconciled внешними событиями. Аварийный отказ может нарушить SLO; online-переход не означает бесконечную доступность при потере PostgreSQL. Если нужен lossless быстрый обратный switch, требуется отдельный reverse CDC проект; в выбранный упрощённый вариант он не входит.

## 7. Приёмка и дальнейшее сопровождение

- Во время репетиции и cutover вести непрерывные внешние HTTP probes, latency, 5xx, pending/timeout/retry, queue depth и время завершения команд. Не считать один успешный curl доказательством отсутствия даунтайма.
- Проверить FOR UPDATE/advisory locks/deadlocks/retry и конкурентные деньги на реальном PostgreSQL; client idempotency — при commit/ack сбоях. Старые password hash/salt/token должны сохраниться без перехэширования при входе.
- Контролировать worker catch-up, платежный durable inbox и Telegram polling. Не повторять начальную выдачу имущества/резервов; не сбрасывать jobs/outbox ради smoke. Политика игровых часов/просрочек принята до перехода.
- Museum SELECT продолжает работать после W; никакие новые данные туда не поступают. Для ресурсов/backup/security старой MariaDB нужен отдельный эксплуатационный план, даже если новый backend больше не поддерживает MySQL.
- После периода наблюдения убрать MySQL/MariaDB jobs нового backend, предварительно перенести уникальные Telegram/world-test-credits/production-schema сценарии на PostgreSQL. Unit/API/деньги/права/конкуренцию/миграции/runtime/deploy guards сохранить. Музей проверяется отдельным read-only smoke, а не полной прежней матрицей.

Операция считается завершённой только после доказательства W/PG соответствия, единственного владельца записи, завершения всех принятых команд по одному разу, успешного PostgreSQL/queue restore и измеренного соблюдения SLO. Подготовка документов не доказывает ни одно из этих условий.
