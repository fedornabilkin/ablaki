<?php
namespace backend\widgets;

use yii\base\Widget;
use yii\helpers\Html;

/** Same world counters on the admin home and the world control overview. */
final class WorldSummary extends Widget
{
    public array $counts = [];
    public function run(): string
    {
        $stats = ['objects' => ['Объекты', 'globe', 'world_node'], 'members' => ['Игроки', 'users', 'world_membership'],
            'items' => ['Стопки предметов', 'cubes', 'craft_inventory'], 'recipes' => ['Рецепты', 'gavel', 'craft_recipe'],
            'builds' => ['Стройки', 'building', 'world_build'], 'trades' => ['Сделки за 24 ч', 'exchange', 'world_market_trade']];
        $html = '';
        foreach ($stats as $key => [$label, $icon, $table]) {
            $html .= Html::a('<span><i class="fa fa-' . $icon . '" aria-hidden="true"></i>' . $label . '</span><strong>'
                . number_format($this->counts[$key] ?? 0, 0, ',', ' ') . '</strong>',
                ['/world/control/index', 'entity' => $table], ['class' => 'wc-stat']);
        }
        return Html::tag('div', $html, ['class' => 'wc-stats']);
    }
}
