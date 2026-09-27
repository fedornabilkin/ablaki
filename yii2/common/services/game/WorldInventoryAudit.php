<?php
namespace common\services\game;

use common\modules\economy\service\FinanceReadSnapshot;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Read-only diagnostics for both legacy and canonical inventory. */
class WorldInventoryAudit
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function sample(Query $query): array
    {
        $count = (int)(new Query())->from(['audit_rows' => clone $query])->count('*', $this->db);
        return ['count' => $count, 'sample' => (clone $query)->limit(1000)->all($this->db), 'truncated' => $count > 1000];
    }
    public function report(): array
    {
        foreach (['user', 'craft_inventory', 'craft_item', 'persone'] as $table) if (!$this->db->schema->getTableSchema($table)) throw new \RuntimeException('Missing table: ' . $table);
        $schema = $this->db->schema->getTableSchema('craft_inventory');
        $canonical = isset($schema->columns['storage_id']) && $this->db->schema->getTableSchema('craft_storage');
        return (new FinanceReadSnapshot($this->db))->run(function () use ($schema, $canonical) {
            $inventory = (new Query())->from(['i' => 'craft_inventory']);
            $orphans = (clone $inventory)->leftJoin(['u' => 'user'], '[[u.id]]=[[i.user_id]]')->leftJoin(['item' => 'craft_item'], '[[item.id]]=[[i.item_id]]')
                ->where(['or', ['and', ['not', ['i.user_id' => null]], ['u.id' => null]], ['and', ['not', ['i.item_id' => null]], ['item.id' => null]]])->select(['i.id', 'i.user_id', 'i.item_id']);
            $group = ['user_id', 'slot']; if (isset($schema->columns['container_id'])) $group[] = 'container_id';
            $legacy = (new Query())->from('craft_inventory')->select(array_merge($group, ['rows' => new Expression('COUNT(*)')]))
                ->where(['>', 'item_quantity', 0])->andWhere(['not', ['item_id' => null]])->andWhere(['not', ['slot' => null]])->groupBy($group)->having('COUNT(*) > 1');
            if (isset($schema->columns['storage_id'])) $legacy->andWhere(['storage_id' => null]);
            $issues = [
                'orphan_inventory' => $this->sample($orphans),
                'duplicate_legacy_positions' => $this->sample($legacy),
                'negative_quantities' => $this->sample((clone $inventory)->select(['i.id', 'i.user_id', 'i.item_quantity'])->where(['<', 'i.item_quantity', 0])),
                'positive_quantity_without_item' => $this->sample((clone $inventory)->select(['i.id', 'i.user_id', 'i.item_quantity'])->where(['i.item_id' => null])->andWhere(['>', 'i.item_quantity', 0])),
            ];
            $nonUser = (clone $inventory)->where(['i.user_id' => null])->andWhere(['>', 'i.item_quantity', 0]);
            $nonUserFields = ['i.id', 'i.item_id', 'i.item_quantity'];
            if ($canonical) {
                $issues['duplicate_storage_positions'] = $this->sample((new Query())->from('craft_inventory')->select(['storage_id', 'slot', 'rows' => new Expression('COUNT(*)')])
                    ->where(['not', ['storage_id' => null]])->andWhere(['not', ['slot' => null]])->groupBy(['storage_id', 'slot'])->having('COUNT(*) > 1'));
                $issues['orphan_storage'] = $this->sample((clone $inventory)->leftJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')->select(['i.id', 'i.storage_id'])
                    ->where(['not', ['i.storage_id' => null]])->andWhere(['s.id' => null]));
                $issues['invalid_storage_position'] = $this->sample((clone $inventory)->select(['i.id', 'i.storage_id', 'i.slot'])->where(['not', ['i.storage_id' => null]])
                    ->andWhere(['>', 'i.item_quantity', 0])->andWhere(['or', ['i.slot' => null], ['<', 'i.slot', 1]]));
                $issues['non_user_stock_without_storage'] = $this->sample((clone $nonUser)->select(['i.id', 'i.item_id'])->andWhere(['i.storage_id' => null]));
                $nonUser->leftJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]');
                $nonUserFields = array_merge($nonUserFields, ['i.storage_id', 's.kind', 's.owner_user_id', 's.node_id']);
                if (isset($this->db->schema->getTableSchema('craft_storage')->columns['owner_actor_id'])) $nonUserFields[] = 's.owner_actor_id';
            }
            $money = $this->db->createCommand('SELECT COUNT(*) AS accounts, MIN([[credit]]) AS minimum_credit, MAX([[credit]]) AS maximum_credit, SUM([[credit]]) AS total_credit FROM {{%persone}}')->queryOne();
            return ['generated_at' => time(), 'read_only' => true, 'report_version' => 2, 'sample_limit' => 1000, 'issues' => $issues,
                'non_user_stock' => $this->sample($nonUser->select($nonUserFields)), 'credit_summary' => $money, 'activation_allowed' => false,
                'note' => 'Counts cover the full query; samples are capped. Null user_id alone is not an orphan: non-user ownership requires domain review. Legacy and canonical positions are grouped separately. Snapshot consistency requires transactional tables; see world-audit/schema. Credit aggregates are diagnostic, not exact money reconciliation; use world-audit/credits.'];
        });
    }
}
