<?php
namespace common\modules\world\models\admin;

use yii\db\Query;

final class WorldBaseline
{
    public static function run(\yii\db\Connection $db, int $afterUser, int $limit): array
    {
        if ($afterUser < 0) throw new \InvalidArgumentException('Cursor must be non-negative.');
        $limit = max(1, min(1000, $limit));
        $users = (new Query())->select('id')->from('user')->where(['>', 'id', $afterUser])->orderBy(['id' => SORT_ASC])->limit($limit)->column($db);
        $result = ['generated_at' => time(), 'read_only' => true, 'scope' => 'user_page', 'users' => [],
            'note' => 'One transaction per page; consistency requires transactional tables. Pages are not a global snapshot while writers run. This legacy user baseline does not cover NPC/system stock or all world state.'];
        foreach ($users as $id) {
            $inventory = (new Query())->from('craft_inventory')->where(['user_id' => $id])->orderBy(['id' => SORT_ASC])->all($db);
            $totals = (new Query())->select(['item_id', 'quantity' => new \yii\db\Expression('SUM([[item_quantity]])')])->from('craft_inventory')->where(['user_id' => $id])->groupBy('item_id')->orderBy(['item_id' => SORT_ASC])->all($db);
            $entry = ['user_id' => (int)$id, 'inventory' => $inventory, 'totals' => $totals,
                'credit' => (new Query())->select('credit')->from('persone')->where(['user_id' => $id])->scalar($db)];
            foreach (['craft_container', 'craft_capacity', 'craft_slot_lease', 'craft_tool_wear', 'craft_skill', 'craft_known'] as $table) {
                if ($db->schema->getTableSchema($table)) $entry[$table] = (new Query())->from($table)->where(['user_id' => $id])->all($db);
            }
            // Results contain no tokens; digest establishes that accepted legacy commands are preserved.
            $digest = hash_init('sha256'); $commands = 0;
            foreach ((new Query())->select(['id', 'request_key', 'fingerprint', 'result'])->from('craft_command')->where(['user_id' => $id])->orderBy(['id' => SORT_ASC])->each(100, $db) as $command) { hash_update($digest, json_encode($command, JSON_THROW_ON_ERROR)); $commands++; }
            $entry['commands'] = ['count' => $commands, 'sha256' => hash_final($digest)];
            $result['users'][] = $entry;
        }
        $result['next_after_user'] = $users ? (int)end($users) : null;
        return $result;
    }
}
