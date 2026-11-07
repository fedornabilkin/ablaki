<?php
namespace common\modules\world\admin;

use yii\db\Connection;

final class ControlCatalog
{
    private ?array $cache=null;
    public function __construct(private readonly Connection $db) {}
    public function entries(): array
    {
        if($this->cache!==null) return $this->cache;
        $known = WorldEntityCatalog::definitions();
        $labels = ['world_node'=>'Объекты мира','craft_item'=>'Предметы','craft_recipe'=>'Рецепты',
            'craft_command'=>'Операции крафта','craft_skill'=>'Навыки изготовления','craft_known'=>'Изученные рецепты',
            'world_npc_policy'=>'Инфраструктура NPC','world_npc_funding'=>'Правила пополнения NPC',
            'world_npc_revenue'=>'Выручка NPC','world_npc_funding_run'=>'Пополнения источников',
            'world_admin_correction'=>'Ручные исправления','world_market_trade'=>'Сделки рынка','world_npc_market'=>'Рынки',
            'economy_transfer'=>'Движение денег','world_build'=>'Строительство','world_build_work'=>'Работа строителей',
            'world_player_presence'=>'Местоположение игроков'];
        $labels+=['craft_equipment_instance'=>'Экземпляры оборудования','craft_recipe_item'=>'Материалы рецептов','craft_recipe_tool'=>'Инструменты рецептов',
            'craft_history'=>'История изготовления','craft_category'=>'Категории предметов','economy_obligation'=>'Обязательства',
            'economy_contract'=>'Договоры','economy_debt'=>'Долги','world_presence'=>'Местоположение игроков','inventory_movement'=>'Движение предметов'];
        $entries = [];
        foreach ($this->db->schema->getTableNames() as $table) {
            if (!in_array($table,['npc','inventory_movement'],true) && !preg_match('/^(world_|craft_|economy_|npc_|production_|quest_)/D',$table)) continue;
            $group = str_starts_with($table,'economy_') ? 'economy' : (str_starts_with($table,'craft_') ? 'craft' : 'objects');
            if ($table==='npc' || str_starts_with($table,'world_npc_') || str_starts_with($table,'npc_') || str_contains($table,'market')) $group = 'npc';
            if (preg_match('/membership|player_|health|housing_interval|skill|known|goal_claim|quest_/', $table)) $group = 'people';
            if (in_array($table,['world_presence','world_source_work'],true)) $group='people';
            if (preg_match('/inventory|equipment_instance|craft_storage|tool_wear|container|capacity|slot_lease/',$table)) $group = 'items';
            if (in_array($table,['craft_item','craft_category','inventory_movement'],true)) $group='items';
            if (preg_match('/audit|correction|change_set|change_item/',$table)) $group = 'audit';
            $entries[$table] = ['label'=>$labels[$table] ?? $known[$table]['label'] ?? str_replace('_',' ', $table), 'group'=>$group];
        }
        uasort($entries,static fn($a,$b)=>strcmp($a['label'],$b['label']));
        return $this->cache=$entries;
    }
    public static function groups(): array
    {
        return ['overview'=>['Обзор','dashboard'], 'economy'=>['Экономика','line-chart'], 'npc'=>['NPC и заработок','refresh'],
            'objects'=>['Объекты','globe'], 'items'=>['Имущество','cubes'], 'craft'=>['Крафт','gavel'],
            'people'=>['Игроки','users'], 'audit'=>['История правок','history']];
    }
    public static function primary(): array
    {
        return ['world_node','world_template','world_event','world_map_cell','world_build','world_crop','world_crop_cycle',
            'craft_item','craft_inventory','craft_storage','craft_equipment_instance','craft_recipe','craft_recipe_item','craft_recipe_tool','craft_history',
            'economy_account','economy_transfer','economy_obligation','economy_contract','economy_debt',
            'world_npc_funding','world_npc_revenue','world_npc_funding_run','world_npc_policy','world_npc_market','world_market_trade',
            'world_membership','world_player_health','world_presence','world_source_work','quest_instance','world_goal_claim',
            'world_admin_correction','world_audit'];
    }
    public function schema(string $entity): \yii\db\TableSchema
    {
        if (!isset($this->entries()[$entity])) throw new \yii\web\NotFoundHttpException('Сущность мира не найдена.');
        return $this->db->schema->getTableSchema($entity);
    }
    public static function immutable(string $entity): bool
    {
        // Facts and accepted snapshots are evidence, not editable current state.
        if (preg_match('/_(history|audit|event|receipt|claim|revision|settlement|payment|accrual)$/D', $entity)) return true;
        return in_array($entity,['world_admin_correction','world_audit','economy_transfer','world_npc_revenue','world_npc_funding_run','world_market_trade','inventory_movement','world_economy_profile','world_economy_usage','world_node_closure'],true);
    }
}
