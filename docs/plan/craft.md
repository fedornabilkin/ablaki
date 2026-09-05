# План: крафт на бэкенде (REST для интерфейса крафта)

## Контекст

Фронт (`frontend/docs/plan/craft.md`) реализует UI крафта (страница `/craft`, инвентарь + рецепты + кнопка «Скрафтить»). На бэке этого ничего нет: модули `common/modules/` сегодня — `exchange`, `forum`, `games`; никакого `craft`, `recipe`, `item`, `inventory` в моделях/миграциях/контроллерах.

Цель — добавить модуль `common/modules/craft/` по образцу `common/modules/forum/`. После выполнения REST-эндпоинты появятся под `v1/craft-*`, и фронт без правок переключится с in-memory мока на боевой бэк — там же та же модель данных.

Контракт REST согласован с `frontend/docs/plan/craft.md`:

- `GET v1/craft-item` — список всех предметов справочника.
- `GET v1/craft-recipe` — список всех рецептов с `ingredients[]` и `output`.
- `GET v1/craft-recipe/{id}` — один рецепт.
- `GET v1/craft-inventory/my` — инвентарь текущего пользователя: `[{item, qty}]`.
- `POST v1/craft-recipe/{id}/craft` — атомарно: проверка ингредиентов и стоимости, списание, выдача результата. Ответ: `{result_item, qty, inventory}`.

## Принципы

- Каждый пункт `д.N` — самостоятельная единица. Группы выполняются в любом порядке внутри; между группами зависимости описаны явно (миграции до моделей, модели до контроллеров).
- Не трогаем существующие модули. Всё новое — в `common/modules/craft/`.
- Соглашения: PSR-4 `common\modules\craft`, миграции через `make migration`, REST в `api/controllers/` модуля, регистрация через `Module::init()` + `App::urlManager()->addRules(...)` (как в `common/modules/forum/Module.php` и `common/modules/forum/config/urlRules.php`).
- Транзакционность: `actionCraft` оборачивается в `Yii::$app->db->beginTransaction()` с `commit/rollback`.

## Группа 1: схема БД и миграции (можно делать первыми)

- [ ] **д.1** — Миграция `m_create_craft_item`: таблица `craft_item` (`id SERIAL`, `code VARCHAR(64) UNIQUE`, `name VARCHAR(120)`, `description TEXT NULL`, `icon VARCHAR(64) NULL` (имя FA-иконки), `category VARCHAR(16)` (`material|product|consumable`), `rarity VARCHAR(16) DEFAULT 'common'`, `created_at`, `updated_at`).
- [ ] **д.2** — Миграция `m_create_craft_recipe`: таблица `craft_recipe` (`id SERIAL`, `name VARCHAR(120)`, `description TEXT NULL`, `output_item_id INT REFERENCES craft_item(id)`, `output_qty INT DEFAULT 1`, `cost_credits NUMERIC(10,2) DEFAULT 0`, `time_seconds INT DEFAULT 0`, `category VARCHAR(32) NULL`, `active BOOL DEFAULT TRUE`, `created_at`, `updated_at`).
- [ ] **д.3** — Миграция `m_create_craft_recipe_ingredient`: таблица `craft_recipe_ingredient` (`recipe_id INT REFERENCES craft_recipe(id) ON DELETE CASCADE`, `item_id INT REFERENCES craft_item(id)`, `qty INT NOT NULL`, PRIMARY KEY `(recipe_id, item_id)`).
- [ ] **д.4** — Миграция `m_create_craft_inventory`: таблица `craft_inventory` (`user_id INT REFERENCES user(id) ON DELETE CASCADE`, `item_id INT REFERENCES craft_item(id)`, `qty INT NOT NULL DEFAULT 0`, `updated_at`, PRIMARY KEY `(user_id, item_id)`). CHECK `qty >= 0`.
- [ ] **д.5** — Сид-миграция `m_seed_craft_defaults`: добавить 9 предметов (5 материалов: Дерево/Камень/Верёвка/Ткань/Серебро; 4 продукта: Факел/Корзина/Амулет/Лук) и 4 стартовых рецепта (Факел, Корзина, Амулет, Лук) с ингредиентами. Иконки/имена — те же, что в `frontend/docs/plan/craft.md` (г.16).

## Группа 2: модели и query (требует д.1–д.4)

- [ ] **д.6** — `common/modules/craft/models/CraftItem.php` + `CraftItemQuery.php`: ActiveRecord по образцу `common/modules/forum/models/ForumTheme.php`. `TimestampBehavior` для `created_at/updated_at`.
- [ ] **д.7** — `common/modules/craft/models/CraftRecipe.php` + `CraftRecipeQuery.php`: связи `getOutputItem()`, `getIngredients()` (через `CraftRecipeIngredient`). Метод `fields()` возвращает payload: `{id, name, description, output: {…item…}, output_qty, cost_credits, ingredients: [{item, qty}], category}`.
- [ ] **д.8** — `common/modules/craft/models/CraftRecipeIngredient.php`: AR с PK `(recipe_id, item_id)`, связь `getItem()`.
- [ ] **д.9** — `common/modules/craft/models/CraftInventory.php`: AR. Статический хелпер `findOrCreate(userId, itemId)`. Метод `add($qty)` / `subtract($qty)` с CHECK `>= 0`.

## Группа 3: сервис крафта (требует д.6–д.9)

- [ ] **д.10** — `common/modules/craft/service/CraftService.php`: метод `execute(int $recipeId, int $userId): array`.
  - Внутри открывает транзакцию.
  - Лочит строки инвентаря пользователя (`SELECT ... FOR UPDATE` для каждого item_id из ингредиентов) и проверяет qty.
  - При нехватке — `throw new CraftException('Не хватает ингредиента: …')`.
  - Если `cost_credits > 0` — списывает кредиты через `Persone` (по образцу `common/modules/exchange/service/`).
  - Списывает ингредиенты, прибавляет output (`findOrCreate` + `add(qty)`).
  - Коммитит, возвращает `['result_item' => Item, 'qty' => N, 'inventory' => CraftInventory[]]`.
- [ ] **д.11** — `common/modules/craft/exception/CraftException.php`: класс исключения с `getReason()` для красивого вывода.

## Группа 4: модуль и REST (требует д.6–д.10)

- [ ] **д.12** — `common/modules/craft/Module.php`: класс модуля по образцу `common/modules/forum/Module.php` (i18n + URL-правила). Регистрация в `common/config/main.php` `modules.craft` и в `api/config/main.php` `bootstrap` (по аналогии с `forum`).
- [ ] **д.13** — `common/modules/craft/config/urlRules.php`: `UrlRule` для контроллеров `v1/craft-item`, `v1/craft-recipe`, `v1/craft-inventory` с `extraPatterns`: `GET inventory/my => my`, `POST recipe/{id}/craft => craft`.
- [ ] **д.14** — `common/modules/craft/api/controllers/CraftItemController.php`: `ActiveController` с экшеном `index` (без auth, справочник).
- [ ] **д.15** — `common/modules/craft/api/controllers/CraftRecipeController.php`: `ActiveController` с экшенами `index`/`view` + кастомный `actionCraft($id)`. Внутри `actionCraft` дёргает `CraftService::execute($id, Yii::$app->user->id)`, ловит `CraftException`, возвращает `400` с `{errors: {reason: '...'}}`.
- [ ] **д.16** — `common/modules/craft/api/controllers/CraftInventoryController.php`: `ActiveController`; экшен `actionMy()` возвращает инвентарь текущего пользователя — массив `[{item: …, qty: N}]`. Эндпоинт `GET v1/craft-inventory/my`, требует авторизации.

## Группа 5: i18n и тесты

- [ ] **д.17** — `common/modules/craft/messages/ru-RU/craft.php` — переводы строк (`attributeLabels`, ошибки сервиса).
- [ ] **д.18** — Codeception-тесты `common/tests/unit/modules/craft/CraftServiceTest.php`:
  - успешный крафт списывает ингредиенты и прибавляет output;
  - крафт при нехватке ингредиентов кидает `CraftException`, инвентарь без изменений;
  - крафт с `cost_credits` списывает кредиты пользователя;
  - параллельные крафты не уводят qty в минус (тест с явной блокировкой).
- [ ] **д.19** — Smoke: после `make migration`, через curl:
  - `GET /v1/craft-item` → массив 9 предметов;
  - `GET /v1/craft-recipe` → 4 рецепта с заполненными `ingredients`;
  - `GET /v1/craft-inventory/my` (с auth) → пусто на старте;
  - `POST /v1/craft-recipe/1/craft` (рецепт «Факел») → 400 «Не хватает Дерева».

## Группа 6: стартовый инвентарь (опционально)

- [ ] **д.20** — Хук «новому пользователю — стартовый набор материалов». Слушатель события `User::EVENT_AFTER_INSERT` или в `Persone::afterSave()` создаёт по 5 единиц каждого материала в `craft_inventory`. Альтернатива — отдельная команда `php yii craft/grant-starter user_id` для админки.

## Группа 7: магазин материалов за кредиты

- [ ] **д.21** — Расширить миграцию `m_create_craft_item` (`д.1`) колонкой `price_credits NUMERIC(10,2) DEFAULT 0 NOT NULL`. В seed (`д.5`) проставить цены материалам: дерево/палки/верёвка/ткань/нить — 1 Cr, камень/уголь/олово/пластик/кожа — 2 Cr, медь — 3, железо — 4, серебро — 8.
- [ ] **д.22** — `common/modules/craft/api/controllers/CraftShopController.php`: `ActiveController`. Экшен `actionIndex()` отдаёт все `craft_item` с `category='material' AND price_credits > 0`. Экшен `actionBuy($id)` — POST с body `{qty}`. UrlRule: `GET v1/craft-shop`, `POST v1/craft-shop/{id}/buy`.
- [ ] **д.23** — Расширить `CraftService` (`д.10`) методом `buy(int $itemId, int $qty, int $userId): array`:
  - открывает транзакцию;
  - SELECT FOR UPDATE по `persone` (баланс кредитов) и по строке инвентаря;
  - валидирует: `qty >= 1`, `item.category === 'material'`, `item.price_credits > 0`;
  - проверяет `persone.credit >= item.price_credits * qty`;
  - списывает кредиты, прибавляет материал в `craft_inventory` (`findOrCreate.add(qty)`);
  - коммитит;
  - возвращает `{item, qty, inventory, credit}` для обновления стора.
- [ ] **д.24** — `CraftException` (`д.11`) использовать для «недостаточно кредитов»/«предмет не продаётся»; контроллер возвращает 400 с `{errors: {reason: '...'}}`.
- [ ] **д.25** — Расширить тесты `д.18`: успешная покупка списывает кредиты и прибавляет материал; покупка при нехватке кредитов → `CraftException`, инвентарь не меняется; попытка купить продукт (не материал) → 400 с понятной ошибкой.
- [ ] **д.26** — Smoke (после `д.21`-`д.23`): `GET /v1/craft-shop` отдаёт 13 материалов с ценами; `POST /v1/craft-shop/1/buy {qty:3}` от залогиненного юзера с балансом ≥ цены — 200 с новым инвентарём и обновлённым `credit`; повторный вызов при нулевом балансе → 400 «не хватает кредитов».

## Зависимости фронта от бэка

Фронт ожидает:
- Те же поля `Item`/`Recipe`/`InventoryEntry`, что в `frontend/docs/plan/craft.md` (Контекст). Если поле переименовано — менять надо здесь, чтобы фронт-код остался.
- Формат ошибок — `{errors: {reason: '...'}}` в body при HTTP 400 (стандарт проекта).
- При успешном `POST /v1/craft-recipe/{id}/craft` — JSON `{result_item, qty, inventory}` (как в `г.18` фронт-плана). `inventory` — массив `[{item, qty}]`.
- `craft_item.price_credits` (NUMERIC) — цена покупки за 1 шт. на бирже материалов. `0` или `NULL` означает «нельзя купить» (это все `category='product'`).
- При успешном `POST /v1/craft-shop/{id}/buy` — JSON `{item, qty, inventory, credit}`. Стор берёт оттуда обновлённый инвентарь и баланс.
