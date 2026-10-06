<?php
namespace common\modules\world\modules\craft\models\domain;

use Yii;

final class CraftSeed
{
    public static function run(\yii\db\Connection $db): bool
    {
        $installed=(new \yii\db\Query())->from('craft_recipe')->where(['code'=>'classic-plank'])->exists($db);
        $catalog=new CraftCatalog(new CraftStorage($db));
        $data=require Yii::getAlias('@common/modules/world/modules/craft/data/default-catalog.php');
        if($installed) {
            // Add new content only; an existing catalog remains under administrator control.
            foreach(['categories'=>'craft_category','items'=>'craft_item','stations'=>'craft_station','recipes'=>'craft_recipe'] as $group=>$table) {
                $existing=(new \yii\db\Query())->select('code')->from($table)->column($db);
                $data[$group]=array_values(array_filter($data[$group],static function($row)use($existing){return $row['code']==='classic-space-elixir'&&!in_array($row['code'],$existing,true);}));
            }
            if(!$data['items']&&!$data['recipes'])return false;
        }
        // Legacy display names can overlap; stable codes remain distinct and existing rows stay intact.
        foreach(['categories'=>'craft_category','items'=>'craft_item','recipes'=>'craft_recipe'] as $group=>$table){
            $names=array_map('trim',(new \yii\db\Query())->select('name')->from($table)->column($db));
            foreach($data[$group] as &$row)if(in_array($row['name'],$names,true))$row['name']=mb_substr($row['name'],0,39).' (крафт)';unset($row);
        }
        $preview=$catalog->preview($data);$catalog->apply($data,$preview['digest']);
        return true;
    }
}
