<?php
namespace common\modules\world\domain;

/** Ordered guidance, independent of persistence, HTTP and reward accounting. */
final class DevelopmentRoute
{
    public static function stages(array $profile = []): array
    {
        return [
            self::stage('home', 'Своя стоянка и ночлег', 'Стройте самостоятельно: бюджет строителей 0. До исследования выберите профессию на странице персонажа: три исследования дают опыт для второго уровня. Выкупайте только нужные ячейки. Кровать разместите в шалаше и выберите проживание.', [
                self::task('city_joined', 'Заселиться в город', 'world'),
                self::build('starter-site', 'Стоянка', 'city'),
                self::task('daily_supply_claimed', 'Получить стартовое снабжение', 'starter-site'),
                self::task('explorer-enrolled', 'Выбрать профессию исследователя', 'starter-site', 'character'),
                self::task('map_cell_explored', 'Исследовать три соседние ячейки', 'starter-site', 'map', 3),
                self::task('explorer-level', 'Повысить исследователя до второго уровня', 'starter-site', 'character', 2),
                self::build('starter-shelter', 'Шалаш'),
                self::task('produced:classic-bed', 'Изготовить кровать', 'starter-site', 'craft'),
                self::task('lodged', 'Разместить кровать и устроиться на ночлег', 'starter-shelter'),
            ]),
            self::stage('mining', 'Шахта и первый заработок', 'Каменная кирка уже в рюкзаке. На своей шахте вы получаете сырьё, на чужой — указанную оплату. Сравните заказы города, выкуп ресурсов на рынке и оплату работы. Оставляйте часть партий и ресурсов для своего строительства. Выкуп и наём зависят от доступного фонда NPC. Ежедневно забирайте снабжение на стоянке и обычный ежедневный кредит.', [
                self::task('acquired:world-stone-pickaxe', 'Получить каменную кирку', 'starter-site', 'craft'),
                self::build('mine', 'Шахта'),
                self::task('own-source:mine-stone', 'Добыть камень в своей шахте', 'mine'),
                self::task('source:gather-coal', 'Добыть уголь', 'mine'),
                self::task('source:gather-ore', 'Добыть руду', 'mine'),
                self::task('paid_work', 'Заработать на оплачиваемой добыче', 'npc-mine'),
                self::build('forest-plot', 'Лесной участок'), self::build('meadow-plot', 'Луговой участок'),
            ]),
            self::stage('food', 'Еда и первые награды', 'Вскопайте грядку, посадите и полейте сразу, затем ещё раз по таймеру. Первый полив без платы, на второй оставьте 2 кредита. Часть моркови превратите в семена. Готовьте на очаге, ешьте в полевой кухне в часы приёмов пищи: так восстановление выше. При голоде работа недоступна. Заберите награды «Лагерь» и «Ресурсы», соберите казну стоянки и оплатите отчисления родителю: деньги вернутся в оборот города.', [
                self::build('garden', 'Огород'), self::build('garden-bed', 'Грядка', 'garden'),
                self::task('produced:world-shovel', 'Изготовить лопату', 'starter-site', 'craft'),
                self::task('bed_dug', 'Вскопать грядку', 'garden-bed'),
                self::task('carrot_sown', 'Посадить морковь', 'garden-bed'),
                self::task('carrot_harvested', 'Собрать первый урожай', 'garden-bed'),
                self::task('produced:starter-carrot-seeds', 'Оставить семена для следующего урожая', 'starter-site', 'craft'),
                self::build('cookfire', 'Очаг'), self::build('field-kitchen', 'Полевая кухня'),
                self::task('produced:world-vegetable-broth', 'Приготовить овощной отвар', 'cookfire', 'craft'),
                self::task('food-eaten', 'Поесть и восстановить здоровье', 'field-kitchen'),
                self::build('campfire-place', 'Место костра'),
                self::task('placed:classic-campfire', 'Изготовить и разместить костёр', 'campfire-place'),
            ]),
            self::stage('production', 'Мастерская и металл', 'До своей мастерской можно пользоваться открытыми станциями города с собственными материалами. Размещайте оборудование под крышей. Сохраняйте камень и палки для замены изношенных инструментов.', [
                self::build('storage-building', 'Хозсклад'),
                self::build('workshop-building', 'Ремесленная мастерская'), self::build('forge', 'Кузница'),
                self::task('produced:classic-hammer', 'Изготовить запасной молоток и открыть рецепт верстака', 'starter-site', 'craft'),
                self::task('placed:classic-bench', 'Изготовить и установить верстак', 'workshop-building', 'craft'),
                self::task('placed:classic-furnace', 'Изготовить и установить плавильню', 'forge', 'craft'),
                self::task('produced:classic-ingot', 'Выплавить первый слиток', 'forge', 'craft'),
            ]),
            self::stage('rooms', 'Дом и дочерние объекты', self::buildingTerms($profile, ['house']) . 'Поставьте кровать в собственном доме и смените ночлег, чтобы увеличить дневной лимит добычи. Несколько домов не складывают бонус. Затем обустройте комнаты, полку и вложенные склады. Для ускорения стройки выделите бюджет помощникам.', [
                self::build('house', 'Дом'), self::build('bedroom', 'Комната', 'house'),
                self::task('lodged-house', 'Поселиться на кровати в собственном доме: +5 партий в день', 'bedroom'),
                self::build('workroom', 'Мастерская', 'house'), self::build('place', 'Место', 'bedroom'),
                self::build('shelf', 'Полка', 'bedroom'), self::build('mini-storage-level-5', 'Мини-склад на стоянке'),
                self::build('mini-storage-level-6', 'Мини-склад в шахте', 'mine'),
                self::build('mini-storage-level-7', 'Мини-склад в комнате', 'bedroom'),
            ]),
            self::stage('sources', 'Собственные источники', 'Добыча и оплачиваемая работа используют общий дневной лимит. Сначала оставьте партии для нужных стройматериалов, остальное время посвятите заработку. Перед каждой стройкой проверяйте цену и запас места.', [
                self::build('water-source', 'Источник воды'), self::build('stone-plot', 'Каменистый участок'),
                self::build('clay-plot', 'Глиняный участок'), self::build('sand-plot', 'Песчаный участок'),
                self::build('trap', 'Ловушка'),
            ]),
            self::stage('expansion', 'Развитое хозяйство', self::buildingTerms($profile, ['small-greenhouse', 'warehouse']) . 'Теплица ускоряет рост и уменьшает потери от слабой непогоды. Создайте в ней отдельную грядку. Большой склад вмещает больше материалов. Расширяйте карту под нужные объекты; в начале достаточно хозсклада.', [
                self::build('small-greenhouse', 'Малая теплица'),
                self::task('built:garden-bed@small-greenhouse', 'Построить грядку в теплице', 'small-greenhouse'),
                self::build('warehouse', 'Склад'),
            ]),
            self::stage('drilling', 'Буровая установка', self::buildingTerms($profile, ['drilling-rig']) . 'Подготовьте материалы по карточке объекта. Буровая повышает выход добычи, заменяет кирку и увеличивает оплату наёмного работника из вашего бюджета. Стройте с перерывами или наймите помощников. Следующие цели — новые шахты, запасы материалов и фонд оплаты труда. Кредиты NPC конечны.', [
                self::build('drilling-rig', 'Буровая установка', 'mine'),
            ]),
        ];
    }

    /** Receives the published runtime profile; no duplicated prices or framework dependencies. */
    private static function buildingTerms(array $profile, array $codes): string
    {
        $terms = [];
        foreach ($codes as $code) {
            $object = $profile['objects'][$code] ?? null;
            if (!$object) continue;
            $price = (string)$object['price_cr'];
            if (str_contains($price, '.')) $price = rtrim(rtrim($price, '0'), '.');
            $seconds = (int)$object['work_seconds'];
            $parts = [];
            if ($seconds >= 3600) $parts[] = intdiv($seconds, 3600) . ' ч';
            if (intdiv($seconds % 3600, 60)) $parts[] = intdiv($seconds % 3600, 60) . ' мин';
            if ($seconds % 60 || !$parts) $parts[] = ($seconds % 60) . ' с';
            $duration = implode(' ', $parts);
            $terms[] = $object['name'] . ': ' . $price . ' Cr и ' . $duration . ' самостоятельной работы.';
        }
        return $terms ? implode(' ', $terms) . ' ' : '';
    }

    private static function stage(string $code, string $title, string $description, array $steps): array
    { return compact('code', 'title', 'description', 'steps'); }

    private static function task(string $fact, string $title, string $node, string $action = 'map', int $required = 1): array
    { return ['code' => $fact, 'title' => $title, 'node' => $node, 'action' => $action, 'required' => $required]; }

    private static function build(string $code, string $title, string $parent = 'starter-site'): array
    { return self::task('built:' . $code, 'Построить: ' . $title, $parent); }

    public static function progress(array $quantities, array $links, array $profile = []): array
    {
        $stages = self::stages($profile); $next = null; $completed = 0; $total = 0;
        foreach ($stages as &$stage) {
            $stage['completed'] = true;
            foreach ($stage['steps'] as &$step) {
                $step['quantity'] = max(0, (int)($quantities[$step['code']] ?? 0));
                $step['completed'] = $step['quantity'] >= $step['required'];
                $step['action_href'] = $links[$step['action'] . ':' . $step['node']] ?? '/world';
                unset($step['node'], $step['action']);
                $stage['completed'] = $stage['completed'] && $step['completed'];
                if (!$step['completed'] && $next === null) $next = $stage['code'];
                $completed += (int)$step['completed']; $total++;
            }
            unset($step);
        }
        unset($stage);
        return ['version' => 1, 'stages' => $stages, 'next_stage' => $next, 'completed' => $completed, 'total' => $total];
    }
}
