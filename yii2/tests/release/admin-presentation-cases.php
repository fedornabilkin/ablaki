<?php
// Included by the full, isolated world control fixture.
use backend\support\{AdminNavigation, EntityDisplay};
use backend\widgets\EntityDataColumn;
use common\modules\world\admin\ControlCatalog;
use yii\data\ArrayDataProvider;
use yii\grid\GridView;

$catalog = (new ControlCatalog($db))->entries();
// p-admin grants world management, but named links must still respect route ACLs.
foreach (['/world/craft/item/view', '/world/control/view'] as $route) {
    $permission = $app->authManager->getPermission($route);
    if (!$permission) { $permission = $app->authManager->createPermission($route); $app->authManager->add($permission); }
    if (!$app->authManager->getAssignment($route,9001)) $app->authManager->assign($permission,9001);
}
$app->user->switchIdentity(new CraftIdentity());
\mdm\admin\components\Helper::invalidate();
$activeLeaves = static function (array $items) use (&$activeLeaves): array {
    $result = [];
    foreach ($items as $item) {
        if (!empty($item['items'])) {
            $children = $activeLeaves($item['items']);
            economyCheck(!$children || !empty($item['active']), 'active child expands its navigation parents');
            $result = array_merge($result, $children);
        } elseif (!empty($item['active'])) $result[] = $item['url'];
    }
    return $result;
};
economyCheck($activeLeaves(AdminNavigation::activate(AdminNavigation::allowedItems(), 'auth-settings/index', []))
    === [['/auth-settings/index']], 'p-admin retains authorization settings without a separate route assignment');
foreach ([
    ['world/control/index', [], ['/world/control/index','group'=>'overview']],
    ['world/control/view', ['entity'=>'craft_inventory'], ['/world/control/index','group'=>'items']],
    ['world/control/edit', ['entity'=>'world_npc_funding'], ['/world/control/index','group'=>'npc']],
    ['world/craft/recipe/update', ['id'=>1], ['/world/craft/recipe/index']],
    ['world/node/view', ['id'=>1], ['/world/node/index']],
    ['user/admin/info', ['id'=>1], ['/user/admin/index']],
] as [$route, $params, $expected]) {
    economyCheck($activeLeaves(AdminNavigation::activate(AdminNavigation::items(), $route, $params, $catalog)) === [$expected], 'one active menu entry on '.$route);
}
$row = (new \yii\db\Query())->from('craft_inventory')->where(['>','item_quantity',0])->one($db);
EntityDisplay::warm([$row], 'craft_inventory');
$item = EntityDisplay::row('craft_item', $row['item_id']);
$html = GridView::widget(['dataProvider'=>new ArrayDataProvider(['allModels'=>[$row]]), 'columns'=>[
    ['class'=>EntityDataColumn::class, 'entityTable'=>'craft_inventory', 'attribute'=>'item_id'],
    ['class'=>EntityDataColumn::class, 'entityTable'=>'craft_inventory', 'attribute'=>'storage_id'],
]]);
$itemLink = EntityDisplay::link('craft_item', $item);
economyCheck(str_contains($html, $itemLink) && str_contains($itemLink,'href='), 'array-backed grids show linked item names');
economyCheck(str_contains(EntityDisplay::link('craft_inventory',$row),'entity=craft_inventory'), 'inventory reference opens the shared record page');
economyCheck(EntityDisplay::value('craft_inventory','item_id',null,$row)==='—', 'empty slots have no broken item link');
$dangerous = ['id'=>$item['id'],'name'=>'<script>not executable</script>'];
economyCheck(!str_contains(EntityDisplay::link('craft_item',$dangerous),'<script>'), 'entity names are escaped');
$html = adminPage('world/control/index',['entity'=>'craft_inventory']);
economyCheck(str_contains($html,'entity-search') && str_contains($html,'entity-lookup'), 'control filters use searchable references');
$html = adminPage('world/control/edit',['entity'=>'craft_inventory','key'=>['id'=>$row['id']]]);
economyCheck(str_contains($html,'entity-search') && str_contains($html,'Причина исправления'), 'audited edit uses named selections and retains required comment');
$html = adminPage('world/control/view',['entity'=>'craft_inventory','key'=>['id'=>$row['id']]]);
economyCheck(str_contains($html,$itemLink), 'control detail uses the same linked entity display');
echo "Admin navigation and entity presentation checks passed.\n";
