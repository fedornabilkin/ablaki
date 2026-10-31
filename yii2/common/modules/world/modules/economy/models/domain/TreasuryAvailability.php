<?php
namespace common\modules\world\modules\economy\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Live template policy; financial accounts and accepted obligations are never removed. */
final class TreasuryAvailability
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<int, bool> */
    public function forNodes(array $ids): array
    {
        if (!$ids) return [];
        // The pre-template schema retains its historical settlement rules.
        if (!isset($this->db->schema->getTableSchema('world_node')->columns['template_id'])) return array_fill_keys($ids, true);
        $rows = (new Query())->select(['n.*', 't.defaults_json'])->from(['n' => 'world_node'])
            ->leftJoin(['t' => 'world_template'], '[[t.id]]=[[n.template_id]]')->where(['n.id' => $ids])->all($this->db);
        $parents = array_fill_keys((new Query())->select('parent_id')->from('world_node')
            ->where(['parent_id' => $ids])->andWhere(['<>', 'status', 'archived'])->distinct()->column($this->db), true);
        $result = [];
        foreach ($rows as $row) {
            $settings = json_decode($row['defaults_json'] ?? '{}', true) ?: [];
            $result[(int)$row['id']] = !\common\modules\world\domain\NodeStructure::isLeaf($row) && ($settings['treasury_enabled'] ?? true) === true && isset($parents[$row['id']]);
        }
        return $result;
    }

    public function enabled(int $node): bool { return $this->forNodes([$node])[$node] ?? false; }
}
