<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\StationResolver;
use common\modules\world\support\GameError;

/** Tools are retained; station availability uses the same location rules as crafting. */
final class BuildRequirements
{
    public static function availability(int $user, int $parent, NodeTemplate $template): array
    {
        $store = new CraftStorage(Node::getDb()); $store->workspaceNodeId = $parent;
        $quantities = []; foreach (BuildMaterials::plan($user, $parent, $template->materials())['materials'] as $material) $quantities[$material['item_id']] = $material['have'];
        $resolver = new StationResolver($store);
        $defaults = $template->defaults(); $missing = [];
        foreach (['material' => $template->materials(), 'tool' => $defaults['build_tools'] ?? [], 'station' => $defaults['build_stations'] ?? []] as $kind => $entries) {
            foreach ($entries as $code => $quantity) {
                $item = CraftItem::find()->where(['code' => $code, 'active' => 1])->one();
                $have = !$item ? 0 : ($kind === 'material' ? ($quantities[$item->id] ?? 0)
                    : count($resolver->candidates($user, (int)$item->id, $kind === 'station', null, (int)$quantity)));
                if ($have < $quantity) $missing[] = ($item ? $item->name : $code) . ': ' . $have . ' / ' . $quantity;
            }
        }
        return ['available' => !$missing, 'unavailable_reason' => $missing ? 'Не хватает: ' . implode(', ', $missing) : ''];
    }
    public static function check(int $user, int $parent, NodeTemplate $template): void
    {
        $defaults = $template->defaults();
        if (empty($defaults['build_tools']) && empty($defaults['build_stations'])) return;
        $store = new CraftStorage(Node::getDb()); $store->workspaceNodeId = $parent;
        $resolver = new StationResolver($store);
        foreach (['build_tools' => false, 'build_stations' => true] as $field => $station) foreach ($defaults[$field] ?? [] as $code => $quantity) {
            $item = CraftItem::find()->where(['code' => $code, 'active' => 1])->one();
            if (!$item || count($resolver->candidates($user, (int)$item->id, $station, null, (int)$quantity)) < $quantity)
                throw new GameError('BUILD_EQUIPMENT_REQUIRED', 'Для строительства нужно: ' . ($item ? $item->name : $code) . ' × ' . $quantity . '.', 422);
        }
    }
}
