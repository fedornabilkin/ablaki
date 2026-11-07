<?php
namespace common\modules\world\models\admin;

use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\value\Money;
use yii\db\{Connection, Query};

final class WorldControlReport
{
    public function __construct(private readonly Connection $db) {}
    public function report(): array
    {
        return (new FinanceReadSnapshot($this->db))->run(function (): array {
            $db=$this->db; $since=time()-86400;
            $count=static function(string $table,array $where=[]) use($db): int { return $db->schema->getTableSchema($table) ? (int)(new Query())->from($table)->where($where)->count('*',$db) : 0; };
            $roles=[];
            if($db->schema->getTableSchema('economy_account')) foreach((new Query())->select(['role','amount'=>'SUM(amount)','reserved'=>'SUM(reserved)'])->from('economy_account')->groupBy('role')->all($db) as $row) {
                $amount=Money::parse((string)$row['amount']); $reserved=Money::parse((string)$row['reserved']);
                $roles[]=['role'=>$row['role'],'amount_cr'=>$amount->decimal(),'reserved_cr'=>$reserved->decimal(),'available_cr'=>$amount->subtract($reserved)->decimal()];
            }
            $funding=$db->schema->getTableSchema('world_npc_funding') ? (new Query())->select(['f.*','name'=>'n.name'])->from(['f'=>'world_npc_funding'])->innerJoin(['n'=>'world_node'],'n.id=f.site_id')->orderBy('f.id')->all($db) : [];
            $sales=$db->schema->getTableSchema('world_npc_revenue') ? (new Query())->select(['channel','operations'=>'COUNT(*)','amount_cr'=>'SUM(amount_cr)','eligible_cr'=>'SUM(eligible_cr)'])->from('world_npc_revenue')->where(['>=','created_at',$since])->groupBy('channel')->all($db) : [];
            $corrections=$db->schema->getTableSchema('world_admin_correction') ? (new Query())->select(['a.id','a.entity','a.comment','a.created_at','u.username'])->from(['a'=>'world_admin_correction'])->leftJoin(['u'=>'user'],'u.id=a.author_id')->orderBy(['a.id'=>SORT_DESC])->limit(8)->all($db) : [];
            $players=(new Query())->select(['m.user_id','u.username','m.joined_at'])->from(['m'=>'world_membership'])->leftJoin(['u'=>'user'],'u.id=m.user_id')->orderBy(['m.id'=>SORT_DESC])->limit(12)->all($db);
            $stock = (new Query())
                ->select(['i.item_id', 'name' => 't.name', 'quantity' => 'SUM(i.item_quantity)', 'stacks' => 'COUNT(*)'])
                ->from(['i' => 'craft_inventory'])->innerJoin(['t' => 'craft_item'], 't.id=i.item_id')
                ->where(['>', 'i.item_quantity', 0])->groupBy(['i.item_id', 't.name'])
                ->orderBy(['quantity' => SORT_DESC, 'i.item_id' => SORT_ASC])->limit(12)->all($db);
            $craft = (new Query())
                ->select(['h.recipe_id', 'name' => 't.name', 'operations' => 'COUNT(*)'])
                ->from(['h' => 'craft_history'])->innerJoin(['t' => 'craft_item'], 't.id=h.item_id')
                ->where(['>=', 'h.created_at', $since])->groupBy(['h.recipe_id', 't.name'])
                ->orderBy(['operations' => SORT_DESC, 'h.recipe_id' => SORT_ASC])->limit(12)->all($db);
            $runs = $db->schema->getTableSchema('world_npc_funding_run')
                ? (new Query())->select(['r.*', 'name' => 'n.name'])->from(['r' => 'world_npc_funding_run'])
                    ->innerJoin(['n' => 'world_node'], 'n.id=r.site_id')->orderBy(['r.id' => SORT_DESC])->limit(12)->all($db)
                : [];
            return ['at'=>time(),'counts'=>['objects'=>$count('world_node',['status'=>'active']), 'members'=>$count('world_membership'),
                'items'=>$count('craft_inventory',['>','item_quantity',0]), 'recipes'=>$count('craft_recipe'), 'builds'=>$count('world_build',['status'=>'building']),
                'trades'=>$count('world_market_trade',['>=','created_at',$since])], 'roles'=>$roles,'funding'=>$funding,'sales'=>$sales,'corrections'=>$corrections,'players'=>$players,
                'stock' => $stock, 'craft' => $craft, 'runs' => $runs,
                'objects' => $this->distribution('world_node', 'node_type'),
                'builds' => $this->distribution('world_build', 'status'),
                'crops' => $this->distribution('world_crop_cycle', 'status'),
                'work' => $this->distribution('world_source_work', 'status'),
            ];
        });
    }

    /** SQL aggregation keeps the dashboard bounded as the world grows. */
    private function distribution(string $table, string $field): array
    {
        if (!$this->db->schema->getTableSchema($table)) return [];
        return (new Query())->select([$field, 'quantity' => 'COUNT(*)'])->from($table)
            ->groupBy($field)->orderBy(['quantity' => SORT_DESC, $field => SORT_ASC])->all($this->db);
    }
}
