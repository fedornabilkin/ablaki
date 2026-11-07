<?php
namespace backend\support;

use common\modules\world\admin\ControlCatalog;
use common\modules\world\admin\WorldEntityCatalog;

/** Navigation follows the controller, including detail and editing pages. */
final class AdminNavigation
{
    public static function items(): array
    {
        $world = [];
        $editors = [
            'objects' => [['Объекты', '/world/node', 'globe'], ['Шаблоны', '/world/template', 'clone'], ['События', '/world/event', 'bolt']],
            'items' => [['Предметы', '/world/craft/item', 'cube'], ['Категории', '/world/craft/category', 'tags'], ['Инвентарь', '/world/craft/inventory', 'suitcase']],
            'craft' => [['Каталог и публикация', '/world/craft/catalog', 'th'], ['Рецепты', '/world/craft/recipe', 'book'], ['История изготовления', '/world/craft/history', 'history']],
        ];
        foreach (ControlCatalog::groups() as $id => [$label, $icon]) {
            $item = ['label' => $label, 'icon' => $icon, 'url' => ['/world/control/index', 'group' => $id]];
            if (isset($editors[$id])) {
                $item['items'] = [['label' => 'Обзор', 'icon' => 'dashboard', 'url' => $item['url']]];
                foreach ($editors[$id] as [$name, $route, $symbol]) $item['items'][] = ['label' => $name, 'icon' => $symbol, 'url' => [$route . '/index']];
                $item['url'] = '#';
            }
            $world[] = $item;
        }
        $world[] = ['label' => 'Расширенные редакторы', 'icon' => 'sliders', 'url' => '#', 'items' => WorldEntityCatalog::menu()];
        return [
            ['label' => 'Главная', 'icon' => 'dashboard', 'url' => ['/site/index']],
            ['label' => 'Мир', 'icon' => 'globe', 'url' => '#', 'items' => $world],
            self::section('Пользователи', 'users', [['Аккаунты', '/user/admin/index', 'user'], ['Профили', '/person/index', 'address-card'], ['Права доступа', '/admin/assignment/index', 'key']]),
            self::section('Модерация', 'shield', [['Задачи', '/todo/index', 'tasks'], ['Факты', '/fact/index', 'check'], ['Вики', '/wiki/index', 'book'], ['Перенаправления', '/redirect/manager/index', 'share'], ['Каталог', '/binds/catalog/index', 'tree']]),
            self::section('Финансы сайта', 'line-chart', [['История баланса', '/history/balance/index', 'money'], ['История рейтинга', '/history/rating/index', 'star'], ['Комиссии', '/commission/index', 'percent'], ['Биржа', '/exchange/credit/index', 'exchange'], ['Переводы', '/exchange/transfer/index', 'long-arrow-right']]),
            self::section('Форум', 'comments', [['Темы', '/forum/theme/index', 'comment'], ['Комментарии', '/forum/comment/index', 'comments-o']]),
            'settings' => self::section('Настройки', 'cog', [['Gii', '/gii/default/index', 'code'], ['Диагностика', '/debug/default/index', 'bug']]),
        ];
    }

    public static function allowedItems(): array
    {
        $items = \mdm\admin\components\Helper::filter(self::items());
        // This existing page is guarded by p-admin in its controller, not route assignments.
        if (!\Yii::$app->user->isGuest && \Yii::$app->user->can('p-admin')) {
            $items['settings'] ??= self::section('Настройки', 'cog', []);
            array_unshift($items['settings']['items'], ['label'=>'Авторизация', 'icon'=>'sign-in', 'url'=>['/auth-settings/index']]);
        }
        return $items;
    }

    private static function section(string $label, string $icon, array $links): array
    {
        return ['label' => $label, 'icon' => $icon, 'url' => '#', 'items' => array_map(static fn($link) => ['label' => $link[0], 'icon' => $link[2], 'url' => [$link[1]]], $links)];
    }

    public static function activate(array $items, string $route, array $params, array $entities = []): array
    {
        $controller = self::controller($route);
        if ($controller === 'world/control') $params['group'] = $entities[$params['entity'] ?? '']['group'] ?? $params['group'] ?? 'overview';
        foreach ($items as &$item) {
            if (isset($item['items'])) {
                $item['items'] = self::activate($item['items'], $route, $params, $entities);
                $item['active'] = in_array(true, array_column($item['items'], 'active'), true);
            } else {
                $url = $item['url'] ?? null;
                $item['active'] = is_array($url) && self::controller($url[0]) === $controller;
                if ($item['active']) foreach ($url as $name => $value) {
                    if ($name !== 0 && (string)($params[$name] ?? '') !== (string)$value) $item['active'] = false;
                }
            }
        }
        return $items;
    }

    private static function controller(string $route): string
    {
        $parts = explode('/', trim($route, '/'));
        array_pop($parts);
        return implode('/', $parts);
    }
}
