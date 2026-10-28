<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\support\GameError;
use yii\db\Query;

/** Physical equipment is checked in this mine; accepted batch terms stay immutable. */
final class DrillingRig
{
    public static function active(Node $mine): ?array
    {
        if (!$mine->template || $mine->template->code !== 'mine') return null;
        $rows = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->where(['n.parent_id' => $mine->id, 'n.owner_user_id' => $mine->owner_user_id, 'n.status' => 'active', 't.code' => 'drilling-rig'])
            ->andWhere(['>', 'n.condition', 0])->orderBy('n.id')->all(Node::getDb());
        foreach ($rows as $row) if (NodeAvailability::activePath(Node::getDb(), (int)$row['id'])) return $row;
        return null;
    }
    public static function bonus(Node $mine, ?array $profile = null): ?array
    {
        if (!$mine->template || $mine->template->code !== 'mine') return null;
        $profile = $profile ?? (EconomyRuntime::current(Node::getDb())['profile'] ?? []);
        $rule = $profile['source_equipment']['drilling-rig'] ?? null;
        if (!$rule || $rule['source'] !== 'mine' || !($rig = self::active($mine))) return null;
        return ['node_id' => (int)$rig['id'], 'output_multiplier' => $rule['output_multiplier'], 'reward_multiplier' => $rule['reward_multiplier']];
    }
    public static function apply(array $definition, ?array $bonus, array $profile): array
    {
        if ($bonus === null) return $definition;
        foreach ($definition['output'] as &$quantity) $quantity *= $bonus['output_multiplier']; unset($quantity);
        $definition['tools'] = array_values(array_diff($definition['tools'], $profile['source_equipment']['drilling-rig']['replaces_tools']));
        $definition['drilling'] = $bonus;
        return $definition;
    }
    public static function requireAccepted(Node $mine, array $definition): void
    {
        if (isset($definition['drilling']) && !self::active($mine)) throw new GameError('SOURCE_EQUIPMENT_REQUIRED', 'Для этой партии нужна исправная буровая в шахте. Восстановите её, чтобы продолжить.', 409);
    }
}
