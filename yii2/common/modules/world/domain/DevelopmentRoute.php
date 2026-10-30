<?php
namespace common\modules\world\domain;

/** Ordered guidance, independent of persistence, HTTP and reward accounting. */
final class DevelopmentRoute
{
    public static function stages(): array
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
            self::stage('mining', 'Шахта и первый заработок', 'Каменная кирка уже в стартовом рюкзаке. На своей шахте вы получаете ресурсы, на чужой — указанную оплату. Сначала проверьте закупки города: продажа угля выгоднее за партию, чем наёмная работа. Заказы ограничены объёмом и сроком. Затем выбирайте работу по оплате за партию и доступному бюджету. Каждый день забирайте сырьё на стоянке и обычный ежедневный кредит.', [
                self::task('acquired:world-stone-pickaxe', 'Получить каменную кирку', 'starter-site', 'craft'),
                self::build('mine', 'Шахта'),
                self::task('own-source:mine-stone', 'Добыть камень в своей шахте', 'mine'),
                self::task('source:gather-coal', 'Добыть уголь', 'mine'),
                self::task('source:gather-ore', 'Добыть руду', 'mine'),
                self::task('paid_work', 'Заработать на оплачиваемой добыче', 'npc-mine'),
                self::build('forest-plot', 'Лесной участок'), self::build('meadow-plot', 'Луговой участок'),
            ]),
            self::stage('food', 'Еда и первые награды', 'Не расходуйте последние семена: часть моркови превратите в новые. Готовьте на очаге, ешьте в полевой кухне в часы приёмов пищи: так восстановление выше. При голоде работа недоступна. Награды первых целей заберите во вкладках «Лагерь» и «Ресурсы», затем соберите казну стоянки. Оплатите отчисления родителю во вкладке «Казна»: эти деньги возвращаются в оборот города.', [
                self::build('garden', 'Огород'), self::build('garden-bed', 'Грядка', 'garden'),
                self::task('produced:world-shovel', 'Изготовить лопату', 'starter-site', 'craft'),
                self::task('bed_dug', 'Вскопать грядку', 'garden-bed'),
                self::task('carrot_sown', 'Посадить и полить морковь', 'garden-bed'),
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
            self::stage('sources', 'Собственные источники', 'Добыча и оплачиваемая работа используют общий дневной лимит. Сначала оставьте партии для нужных стройматериалов, остальное время посвятите заработку. Перед каждой стройкой проверяйте цену и запас места.', [
                self::build('water-source', 'Источник воды'), self::build('stone-plot', 'Каменистый участок'),
                self::build('clay-plot', 'Глиняный участок'), self::build('sand-plot', 'Песчаный участок'),
                self::build('trap', 'Ловушка'),
            ]),
            self::stage('rooms', 'Дом и дочерние объекты', 'Дом — долгосрочная стройка: её можно приостанавливать. Внутри дома создайте комнаты, затем места, полку и вложенные склады. Деньги на дочернюю стройку направьте в бюджет её родителя.', [
                self::build('house', 'Дом'), self::build('bedroom', 'Комната', 'house'),
                self::build('workroom', 'Мастерская', 'house'), self::build('place', 'Место', 'bedroom'),
                self::build('shelf', 'Полка', 'bedroom'), self::build('mini-storage-level-5', 'Мини-склад на стоянке'),
                self::build('mini-storage-level-6', 'Мини-склад в шахте', 'mine'),
                self::build('mini-storage-level-7', 'Мини-склад в комнате', 'bedroom'),
            ]),
            self::stage('expansion', 'Развитое хозяйство', 'Расширяйте карту по мере необходимости: сначала исследование, затем покупка ячейки. Большой склад оставьте на этап, когда есть стабильный заработок. В теплице создайте отдельную грядку.', [
                self::build('small-greenhouse', 'Малая теплица'),
                self::task('built:garden-bed@small-greenhouse', 'Построить грядку в теплице', 'small-greenhouse'),
                self::build('warehouse', 'Склад'),
            ]),
            self::stage('drilling', 'Буровая установка', 'Долгосрочная цель: 2500 кредитов, 250 досок, 300 камня, 80 слитков и 60 верёвок. Храните запас на складе. Самостоятельная стройка — 24 часа активной работы с перерывами, бюджет найма 0. Фонд работодателей конечен: при его исчерпании остаётся ежедневный кредит, поэтому накопление может занять месяцы. Буровая ускоряет последующую добычу.', [
                self::build('drilling-rig', 'Буровая установка', 'mine'),
            ]),
        ];
    }

    private static function stage(string $code, string $title, string $description, array $steps): array
    { return compact('code', 'title', 'description', 'steps'); }

    private static function task(string $fact, string $title, string $node, string $action = 'map', int $required = 1): array
    { return ['code' => $fact, 'title' => $title, 'node' => $node, 'action' => $action, 'required' => $required]; }

    private static function build(string $code, string $title, string $parent = 'starter-site'): array
    { return self::task('built:' . $code, 'Построить: ' . $title, $parent); }

    public static function progress(array $quantities, array $links): array
    {
        $stages = self::stages(); $next = null; $completed = 0; $total = 0;
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
