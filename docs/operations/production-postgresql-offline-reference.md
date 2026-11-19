# Справочный вариант: MariaDB → PostgreSQL с остановкой сайтов

**Не действующий план.** Сохранён только как справочный offline-вариант. Выбран переход без остановки нового сайта и музей на MariaDB: [актуальная инструкция](production-postgresql-migration.md). Не смешивать команды двух сценариев.

План от 2026-11-17. [Задачи и критерии](../plan/2026-11-17-production-postgresql.md).

**Это инструкция для подготовки будущей операции, а не разрешение выполнить её сейчас.** Сервер в этой задаче не обследован. Конфигурация и команды переноса ещё не реализованы/не проверены на production-копии. Сначала закрыть группы A–C плана. Все команды ниже выполняет оператор на сервере в согласованное окно; сейчас никаких серверных изменений не сделано.

## 1. Выбор сценария

| Вариант | Что происходит | Ограничения |
|---|---|---|
| Полностью отключить старый сайт — рекомендуется | Все записи останавливаются; финальная копия переносится; запускается только новый сайт на PostgreSQL | Есть измеренное окно простоя. Самый простой способ избежать расхождения денег и двойных операций |
| Старый сайт остаётся статическим архивом | Перед финальным копированием выключаются его PHP, cron и интеграции; отдельно публикуется заранее сформированный статический контент | Обычный GET старого сайта может писать presence/рекламу/сессии. Предпочтительнее статический архив без PHP, секретов и доступа к БД; не публиковать личные страницы |
| Старый сайт продолжает менять общие данные | Требуется сначала перевести и его на PostgreSQL либо спроектировать CDC/единственный write API и финальный freeze | Это отдельный проект. Простой pgloader не поддерживает живую двустороннюю синхронизацию. Нельзя открыть оба источника записей и затем «дослить разницу» |

Если новые и старые сайты используют разные базы, установить, где источник пользователей/денег и что реально синхронизируется. Нельзя объединять их по совпадающим id без mapping и сверки. При полном отключении не забыть старые домены/IP/IPv6/порты, PHP-FPM pools, cron, systemd timers, Docker restart policies, события MariaDB, платежные callbacks и бота на другом VPS.

DNS не является механизмом согласования: старые клиенты, закэшированные страницы и прямые API продолжают обращаться к прежнему адресу. На старом сервере нужен постоянный gate, а не только смена DNS. Новая статическая SPA тоже не заменяет выключение старого backend.

## 2. Что уже известно из репозитория

- Production checkout backend: `/var/www/api.ablakin.ru`, frontend: `/var/www/ablakin.ru`. Текущие имена контейнеров и port mapping надо проверить; исторический внутренний HTTP probe — `127.0.0.1:2180`.
- `common/config/main.php` выбирает MySQL при заполненных MYSQL_DB_HOST/MYSQL_DB_NAME, иначе PostgreSQL. PG_DB_PORT сейчас не входит в DSN. Заполнение PG_DB_* при оставленных MYSQL_DB_* недостаточно; локальные main-local.php тоже могут переопределить db.
- `ReleaseController::actionWorldUpdate` и production inspect/prepare/activate/rating имеют MySQL-only SQL/guards. Простая замена env сломает дальнейшие релизы.
- Старый PHP entrypoint автоматически выполняет init и исторические миграции, но не гарантирует остановку при ошибке. На переносе нужен подготовленный startup без этих побочных действий.
- FinancialMaintenance использует файловую паузу и lease только для покрытого кодом приложения. Это **не глобальная блокировка** всех потребителей MariaDB.
- При cloud onboarding пустая MariaDB столкнулась с FK `persone.user_id BIGINT` → `user.id INT`, а обычная PostgreSQL-цепочка — с namespace миграции `m260911_120000_convert_forum_text_to_utf8`. Это свидетельство локальной проверки, не утверждение о дефекте работающей production-схемы.
- Существующие storage/wallet/world данные и migration history нужно переносить, а не создавать заново через test-ready/seed/backfill. Проверенные ранее числа владельцев и суммы резервов — исторические, для сверки нужны новые данные T0.

## 3. Контракт ещё не созданных инструментов

Следующие команды `deploy/db-move` — **предлагаемый интерфейс задачи B6, сейчас такого исполняемого файла нет**. Не начинать операцию с их копирования в shell до реализации, ревью и репетиции. Они не должны скрыто выключать проверки, удалять данные, менять историю миграций или открывать трафик при ошибке.

| Команда | Обязательный результат |
|---|---|
| `inventory --config FILE --out DIR` | Подтверждённые source/target identities, список таблиц/типов/писателей/маршрутов; безопасный отчёт, без секретов |
| `gate close/open --config FILE` | Применение заранее проверенного maintenance include ко всем входам, nginx -t до reload; блокирует старый сайт/прямые порты. open открывает только новый сайт |
| `writers stop/verify-stopped --config FILE --out DIR` | Сначала graceful drain, затем остановка всех разрешённых inventory-процессов. Проверка незавершённых транзакций, DB sessions/events и повторный контроль отсутствия изменений; состояние остановки и исходные DB read_only/grants сохраняются |
| `snapshot-restore --config FILE --dump FILE --out DIR` | Восстановить финальный дамп только в отдельную MariaDB той же проверенной версии, с event_scheduler OFF, без external callbacks и доступа приложений; проверить restore. Не трогать production source |
| `pg-prepare --config FILE --out DIR` | Создать новую PostgreSQL 16, отдельный volume и БД; роли importer/owner/runtime, SCRAM, locale/UTF-8/timezone, лимиты и backup. Остановиться при непустой цели; предъявить DDL canonical schema и mappings; не запускать seed |
| `load-config --config FILE --snapshot DIR --out FILE` | Сформировать закрытый pgloader config с единственным snapshot-источником и пустой целью, data-only, проверенными cast/mapping; URI только в chmod 600 файле. Ни skip/reject rows, ни DROP. Результат не печатать |
| `finalize --config FILE --out DIR` | Подтвердить успешный импорт; восстановить/VALIDATE FK, unique/CHECK, views/functions/triggers, migration history по mapping, sequences и grants; runtime не получает владельца/superuser |
| `verify --config FILE --snapshot DIR --out DIR` | Полный отчёт соответствия по правилам раздела 7; ненулевой exit при отличии/пропуске/неполной проверке |
| `switch --config FILE --to postgres/mariadb --out DIR` | Атомарно заменить только согласованные подключения; сохранить original env/local configs; проверить все consumers. Не менять project name, данные и текущие rollout-marker; не запускать миграции/cron |
| `app start-paused/smoke-read/allow-controlled-writes/smoke-write/open --config FILE --out DIR` | Startup FPM без старого entrypoint, все публичные входы закрыты; затем отдельный барьер T1 и выделенные проверочные операции без сокрытия денежных изменений |
| `workers start-staged --config FILE --out DIR` | По одному worker/cron/бот/интеграции с проверкой catch-up и очереди; не дублировать polling и потребителей |
| `checkpoint --config FILE --out DIR` | Зафиксировать identities/SHA/time и факт начала PostgreSQL-записей; после T1 запретить простой switch-to-mariadb |
| `rollback-before-writes --config FILE --out DIR` | Проверить отсутствие PostgreSQL-записей после baseline, остановить новую систему; вернуть только исходную MariaDB-конфигурацию/права/потребителей. При сомнении отказать |
| `resume-source --config FILE --out DIR` | До switch/T1 отменить неудавшийся перенос: вернуть сохранённые source DB настройки и процессы за закрытым gate, smoke; не открывать старый и новый источники одновременно |

Конфигурация `/etc/ablaki-db-move/server.env`: закрытый, проверенный оператором shell-файл без паролей. Поля ниже обязательны; actual имена берутся из inventory:

```bash
BACKEND_ROOT=/var/www/api.ablakin.ru
SOURCE_DB=ablaki
MYSQL_CONTAINER=REPLACE_FROM_INVENTORY
MYSQL_DUMP=mysqldump
MYSQL_CNF_IN_CONTAINER=/run/secrets/db-move-source.cnf
PG_CONTAINER=REPLACE_FROM_REHEARSAL
PG_OWNER=ablaki_owner
TARGET_DB=ablaki_pg
API_LOCAL=http://127.0.0.1:2180
```

Секреты располагаются отдельно: source client defaults file внутри MariaDB-контейнера (ограниченный доступ, права на полный backup), PostgreSQL secret files/.pgpass и защищённый pgloader config. Подготовить их заранее без паролей в shell history, Git, argv и отчётах; не запускать `set -x`, `docker inspect` с полным Env или `docker-compose config` в общий лог. Runtime-роль не равна owner/importer. Дампы и отчёты тоже содержат персональные данные: закрытые каталоги, шифрование и ограниченный срок хранения.

Для MariaDB на другом сервере вместо docker exec используется проверенный клиент с `--defaults-extra-file` и защищённым соединением. Не подставлять контейнер из другого окружения только потому, что совпадает имя БД.

## 4. Подготовка без остановки production

Запускать в отдельной операторской shell. Здесь и далее `RUN_DIR` — уникальная директория одной попытки, не общий latest; записать её путь в журнал операции и сохранить shell-сессию. Все дальнейшие блоки используют те же переменные.

```bash
set -Eeuo pipefail
umask 077
cd /var/www/api.ablakin.ru
RUN_DIR="/var/backups/ablaki-db-move/$(date -u +%Y%m%dT%H%M%SZ)"
sudo install -d -m 0700 -o "$(id -un)" "$RUN_DIR"
CONFIG=/etc/ablaki-db-move/server.env
# Только доверенный локальный файл, проверенный оператором.
source "$CONFIG"

git rev-parse HEAD > "$RUN_DIR/backend-sha.txt"
git status --short > "$RUN_DIR/backend-status.txt"
docker ps -a --format '{{.Names}} | {{.Image}} | {{.Status}} | {{.Ports}}' \
  > "$RUN_DIR/containers.txt"
# Проверить имена сервисов, без вывода секретов.
docker-compose config --services > "$RUN_DIR/compose-services.txt"
df -h > "$RUN_DIR/disk.txt"
test -x deploy/db-move
./deploy/db-move inventory --config "$CONFIG" --out "$RUN_DIR/inventory"
```

Не продолжать при неизвестном потребителе/изменениях кода, иной БД, нехватке места или неизмеренном времени переноса. Не предполагать возможность прямой установки PostgreSQL/pgloader в произвольной ОС: точные пакеты/проверенные образы и digest фиксируются в C6. PostgreSQL 16 выбирается из уже поддерживаемой CI версии, minor/security patch фиксируется по дате операции. Включить backup/WAL retention до запуска; локальный контейнер сам по себе не обеспечивает отказоустойчивость.

Прогнать те же шаги copy/finalize/verify/smoke и rollback на восстановленной копии, а не на production. Планировать время простоя как freeze + backup + restore snapshot + transfer + constraints/indexes + full verification + smoke + запас; наибольший этап надо измерить. Репетиционный dump не заменяет финальный. Автодеплой master, scheduled jobs и ручные операторы блокируются на окно операции.

## 5. Полное отключение старого сайта и финальный backup

Сначала подготовить новый релиз с B1/B2/B8/B9, способный ещё работать на MariaDB. Не совмещать перенос с новой игровой функциональностью. Поддержать операторскую блокировку на весь cutover; повторный запуск команды должен распознавать состояние, а не начинать всё сначала.

```bash
# Общий lock всех попыток, заранее созданный задачей B6 с доступом оператора.
# Не создавать/заменять inode lock-файла во время операции.
test -f /var/lock/ablaki-db-move.lock
test ! -L /var/lock/ablaki-db-move.lock
exec 8</var/lock/ablaki-db-move.lock
flock -n 8
./deploy/db-move gate close --config "$CONFIG"
./deploy/db-move writers stop --config "$CONFIG" --out "$RUN_DIR/stop"
./deploy/db-move writers verify-stopped --config "$CONFIG" --out "$RUN_DIR/freeze"
date -u +%FT%TZ > "$RUN_DIR/T0.txt"
```

Gate обязан закрыть старый серверный UI, новую SPA/API, админку и прямые порты снаружи. Дождаться уже допущенных запросов/платёжных операций; очередь входящих callbacks должна иметь подтверждённый retry или отдельный durable spool. Не отвечать платёжному провайдеру 200 до сохранения события. Остановить external cron/бота отдельно, отключить автоматические рестарты старых контейнеров. При зависшей транзакции остановиться и разобраться; не считать убийство процесса подтверждением успешной бизнес-операции.

Файловая `financial-maintenance/paused` сохраняется как дополнительный барьер, но не заменяет stop всех писателей. В `writers stop` блокировать записи source также правами/DB settings по согласованному inventory: MariaDB read_only не останавливает SUPER/root, а глобальная настройка может задеть чужие базы. Проверить сессии/транзакции и повторные контрольные суммы. Два снимка row count недостаточны для доказательства отсутствия UPDATE.

Пример финального backup для **подтверждённого контейнерного источника** (зафиксированная версия 10.1 обычно имеет mysqldump). Пароль не передаётся аргументом. При раздельных источниках сделать отдельные дампы всех согласованных баз при общей остановке записей.

```bash
: "${MYSQL_CONTAINER:?}" "${MYSQL_DUMP:?}" "${SOURCE_DB:?}" "${MYSQL_CNF_IN_CONTAINER:?}"
docker exec "$MYSQL_CONTAINER" "$MYSQL_DUMP" \
  --defaults-extra-file="$MYSQL_CNF_IN_CONTAINER" \
  --databases "$SOURCE_DB" --lock-all-tables --quick \
  --routines --events --triggers --hex-blob --default-character-set=utf8mb4 \
  | gzip -1 > "$RUN_DIR/source.sql.gz"
test -s "$RUN_DIR/source.sql.gz"
gzip -t "$RUN_DIR/source.sql.gz"
sha256sum "$RUN_DIR/source.sql.gz" > "$RUN_DIR/source.sql.gz.sha256"
./deploy/db-move snapshot-restore --config "$CONFIG" \
  --dump "$RUN_DIR/source.sql.gz" --out "$RUN_DIR/snapshot"
```

Используется `--lock-all-tables`, а не один `--single-transaction`: исторически могли быть MyISAM. Из-за полной остановки писателей состояние стабильно и после освобождения dump-lock. `gzip -t` не доказывает восстановимость — нужен успешный реальный restore и отчёт. Source volume и исходную БД не удалять/не переименовывать. Дамп скопировать в согласованное защищённое хранилище; перенос не начинать без подтверждения резервной копии.

## 6. Схема и загрузка PostgreSQL

```bash
./deploy/db-move pg-prepare --config "$CONFIG" --out "$RUN_DIR/pg-prepare"
./deploy/db-move load-config --config "$CONFIG" \
  --snapshot "$RUN_DIR/snapshot" --out "$RUN_DIR/final.load"
chmod 0600 "$RUN_DIR/final.load"
pgloader --version > "$RUN_DIR/pgloader-version.txt"
# Конфиг содержит секреты. Даже ошибки pgloader могут вывести URI/данные:
# лог и rejects хранятся закрыто, не прикладываются к публичному issue/CI.
mkdir -m 0700 "$RUN_DIR/pgloader-work"
pgloader --root-dir "$RUN_DIR/pgloader-work" "$RUN_DIR/final.load" \
  > "$RUN_DIR/pgloader.log" 2>&1
./deploy/db-move finalize --config "$CONFIG" --out "$RUN_DIR/finalize"
./deploy/db-move verify --config "$CONFIG" \
  --snapshot "$RUN_DIR/snapshot" --out "$RUN_DIR/verification"
```

Источник pgloader — **изолированно восстановленная MariaDB**, не меняющаяся production. Только перенос данных в заранее проверенную схему. На schema creation/import роли и настройки отдельные; приложению доступ пока закрыт. Проверить ноль rejects даже при exit 0. Повтор не должен добавлять копии уже загруженных строк; после незавершённого импорта создать новую цель либо применить проверенный resumable протокол. Не `DROP DATABASE` по скопированной универсальной команде.

Миграции/представления нельзя переносить механически: часть production таблиц может быть представлениями старой модели. Сначала mapping каждого объекта и canonical DDL, затем data-only, затем constraints/views/sequence defaults. Не применять test setup или первичную конвертацию денег к перенесённой рабочей БД. Старые salt/auth_key/hash сохраняются как bytes; `CHAR` с пробелами не приводится к `trim` без регрессий старого входа. Zero dates, invalid UTF-8, большие UNSIGNED, decimal scale и коллизии логинов должны быть разрешены ещё в репетиции, а не отброшены импортом.

## 7. Обязательная сверка до открытия

`verify` обязан дать отчёт по каждой таблице, а не только общий PASS:

1. Полный перечень source и target объектов, отсутствие неожиданно пропущенных таблиц; counts и наборы PK по чанкам. Таблицы без PK — полное сравнение мультимножеств с учётом дубликатов.
2. Канонические хэши строк с однозначным framing полей, типами/NULL, стабильной кодировкой и порядком. Decimal сравнивать точно в принятом масштабе; float — по заранее заданному round-trip правилу, не произвольным epsilon для денег. Все допустимые преобразования перечислены в mapping.
3. Деньги каждого пользователя/счёта, reserved/available, журналы, обязательства резервов, открытые переводы/заявки, состояние незаконченных игр/строек, баланс контрагентов и double-entry инварианты. Сумма без построчной проверки не замечает перестановку владельцев.
4. Вещи/склады/ячейки, enum/state, рецепты, карты мира, административные настройки и активная версия economy profile; поля времени и paused/catch-up policy. Не запускать повторный стартовый seed/NPC funding.
5. Точные пользователи/роли/права/hash/salt/token bytes, Telegram identities/link state/pending flows, idempotency keys, outbox/jobs и delivery attempts. Не очищать очередь ради чистого smoke.
6. Все FK/unique/CHECK проверены, индексы/представления рабочие, migration history соответствует schema manifest. Для каждой sequence — корректная связь с колонкой/default, значение не ниже max(id); для пустых таблиц корректный is_called=false. Провести insert/rollback пробу на копии; увеличение sequence при rollback нормально.
7. Права runtime, отсутствие доступа новой системы к MariaDB, фактические driver/host/database/port всех приложений/CLI/worker. Timezone задаётся явно; collation differences протестированы на кириллице/регистре/дубликатах.

## 8. Переключение при закрытом трафике

```bash
./deploy/db-move switch --config "$CONFIG" --to postgres --out "$RUN_DIR/switch"
./deploy/db-move app start-paused --config "$CONFIG" --out "$RUN_DIR/start"
./deploy/db-move app smoke-read --config "$CONFIG" --out "$RUN_DIR/read-smoke"
```

Switch должен убрать/нейтрализовать MYSQL_DB_* и все local overrides, указать PG host/5432/db/user/password через защищённую конфигурацию. Создавать новые контейнеры с новым env, а не `docker restart` старого env. Не печатать .env. Не менять COMPOSE_PROJECT_NAME, не запускать `make init`, `down -v`, `prune` или бездумный `make up`: старый entrypoint повторяет миграции, а `--remove-orphans` может затронуть worker. После переключения миграция не должна перезапускать conversion/activation и повторно переводить средства.

Read smoke выполняется на специально проверенных маршрутах без presence/сессионных/рекламных записей. `curl` публичной страницы не гарантирует отсутствие записи. Служебные smoke-ответы должны включать проверенную identity PostgreSQL без секретов. Старый сайт всё ещё выключен, публичный gate закрыт. В случае ошибки до T1 возможен быстрый rollback по разделу 10.

**T1 фиксируется перед первой разрешённой записью приложения, включая smoke, cron, логин, callbacks и worker.** После T1 исходная MariaDB больше не является безопасной целью простого отката.

```bash
./deploy/db-move checkpoint --config "$CONFIG" --out "$RUN_DIR/before-write"
date -u +%FT%TZ > "$RUN_DIR/T1-write-barrier.txt"
./deploy/db-move app allow-controlled-writes --config "$CONFIG" --out "$RUN_DIR/write-admission"
./deploy/db-move app smoke-write --config "$CONFIG" --out "$RUN_DIR/write-smoke"
./deploy/db-move workers start-staged --config "$CONFIG" --out "$RUN_DIR/workers"
./deploy/db-move app open --config "$CONFIG" --out "$RUN_DIR/open"
./deploy/db-move gate open --config "$CONFIG"
```

Проверить старый пароль без изменения hash/salt, создание/повтор/отмену безопасной операции и журналы на выделенных реальных проверочных аккаунтах. Не дарить кредиты для проверки и не удалять следы проверки. Запустить worker сначала одной партией, оценить просроченные задания; затем один постоянный экземпляр, cron, платежные повторы и Telegram polling. Учитывать производственные интервалы времени: длительная остановка может вызвать пачку ночей/истечений. Нельзя отбрасывать события или автоматически сдвигать все даты без принятой политики.

## 9. После открытия

```bash
# Публичные smoke без тестовой нагрузки и выводов персональных данных.
curl --fail --silent --show-error --max-time 20 "$API_LOCAL/v1/stat" \
  -o "$RUN_DIR/public-stat.json"
# Локальная backup-команда; имя owner — из проверенного server.env.
# Подготовленная PG роль/peer authentication должна разрешать pg_dump без пароля в argv.
docker exec "$PG_CONTAINER" pg_dump -U "$PG_OWNER" -d "$TARGET_DB" -Fc \
  > "$RUN_DIR/post-cutover.dump"
test -s "$RUN_DIR/post-cutover.dump"
docker exec -i "$PG_CONTAINER" pg_restore --list \
  < "$RUN_DIR/post-cutover.dump" > "$RUN_DIR/post-cutover.contents"
sha256sum "$RUN_DIR/post-cutover.dump" > "$RUN_DIR/post-cutover.dump.sha256"
```

`pg_restore --list` проверяет читаемость архива, не восстановимость. Обязательно восстановить его на отдельном PostgreSQL и повторить read/invariant checks; для принятого RPO настроить WAL/PITR и регулярную проверку restore. Настроить алерты 5xx, latency, locks/deadlocks/retries, соединения/диск/WAL, лаг очередей и денежные расхождения. MariaDB остаётся замороженной; публичный старый сайт не возвращать даже для «проверки». Отследить обращения к старому IP и погашать их gate/статическим сообщением.

## 10. Откат

### До переключения/до T1

Любая ошибка оставляет gate закрытым. Не снимать паузу автоматически в trap. Если switch ещё не был выполнен:

```bash
./deploy/db-move resume-source --config "$CONFIG" --out "$RUN_DIR/resume-source"
# Открывает только ту исходную конфигурацию, которую оператор выбрал при отмене операции.
# Новый PostgreSQL при этом изолирован и не получает приложение/worker.
```

Если уже сменили подключение, но доказано отсутствие PostgreSQL-записей после baseline:

```bash
./deploy/db-move rollback-before-writes --config "$CONFIG" --out "$RUN_DIR/rollback"
```

Эта команда обязана остановить новые процессы, вернуть точные сохранённые env/overrides и прежние source DB grants/read_only, проверить все подключения, восстановить только прежних consumers, сделать smoke за gate. Открытие исходного сайта — отдельное решение оператора после проверки. Остаточные события/callbacks не должны быть воспроизведены дважды. Наличие файла T1 трактовать консервативно: при неопределённости простой rollback запрещён.

### После T1

Не переключать `.env` назад: это потеряет новые переводы/заказы/регистрации, а старые очереди могут исполнить их повторно. Закрыть трафик, остановить писателей PostgreSQL и сохранить новый backup/WAL. Предпочтительный путь — исправить PostgreSQL/код вперёд или восстановить PostgreSQL с WAL до согласованного момента, сохранив принятые события. Restore старого snapshot тоже теряет записи после snapshot без WAL/reconciliation.

Если возврат в MariaDB действительно необходим, это отдельная миграция PostgreSQL→MariaDB с mapping, полной остановкой, обратным переносом всех изменённых/удалённых строк и состояний внешних операций, сверкой и новой точкой допуска. Не считать арифметическую «дельту балансов» достаточной. До такой реализации обещать быстрый lossless rollback после T1 нельзя.

## 11. Когда сокращать тесты

Во время подготовки/периода отката временно нужны обе СУБД плюс тест мигратора. После окончания MariaDB runtime-поддержки можно удалить две дополнительные матрицы СУБД (MariaDB 10.1 и MySQL 8), а не сами проверяемые свойства. В PostgreSQL job перенести уникальные `world-test-credits`, production-schema/Telegram checks; Telegram fixture сейчас привязан к MySQL DSN и должен получить отдельную PostgreSQL fixture, а не silently перейти на SQLite.

Сохраняются быстрые unit/SQLite API, реальный PostgreSQL с FOR UPDATE/advisory locks, конкуренция/idempotency/права/деньги/rollback, миграции и worker, smoke deploy/runtime/CORS/audit. PostgreSQL не отменяет блокировки и гонки. Исторические MySQL-specific migration tests можно вынести в одноразовый/архивный набор только после сверки переноса и отмены дальнейшей поддержки MariaDB. Экономию измерить: по текущему workflow разные jobs параллельны, поэтому сокращение runner-minutes не равно пропорциональному уменьшению wall time.
