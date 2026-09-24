<?php
use yii\db\Query;

// Repeat the serialized part of the parallel race on every supported database.
$transferTx=$db->beginTransaction();
$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
$db->createCommand()->delete('craft_container',['user_id'=>9001])->execute();
$db->createCommand()->delete('craft_command',['user_id'=>9001])->execute();
$material=$items['classic-log'];$chestItem=$items['classic-chest'];
$s->move(9001,$material,100);$s->move(9001,$chestItem,1);
$chestId=$engine->state(9001)['containers'][0]['id'];
$source=(int)(new Query())->select('id')->from('craft_inventory')->where(['user_id'=>9001,'item_id'=>$material['id']])->scalar($db);
$body=['id'=>(int)$material['id'],'slot_id'=>$source,'quantity'=>20,'container_id'=>$chestId,'position'=>1];
for($i=1;$i<=5;$i++) {
    $body['position']=$i;
    $engine->command(9001,'exhaust-source-transfer-'.$i,'transfer',$body);
}
$before=$engine->state(9001);$body['position']=6;
try {
    $engine->command(9001,'exhaust-source-transfer-6','transfer',$body);
    throw new \RuntimeException('A stale source must not move the last deposited stack within the chest.');
} catch(\yii\web\ConflictHttpException $error) {
    checkCraft(sameCraftState($before,$engine->state(9001)),'exhausted source rejects a distinct request without moving chest contents');
}
$container=$before['containers'][0];
checkCraft($container['durability']===95&&array_sum(array_column($container['slots'],'quantity'))===100,'five deposits preserve 100 materials and charge exactly five wear');
$body['position']=5;
$replay=$engine->command(9001,'exhaust-source-transfer-5','transfer',$body);
checkCraft($replay['replayed']&&sameCraftState($before,$replay['state']),'final deposit replays after the original source is exhausted');

// A chest is an instance: moving it must retain its contents and accumulated wear.
$moved=$engine->command(9001,'move-filled-chest-key','transfer',['id'=>(int)$chestItem['id'],'slot_id'=>$chestId,'quantity'=>1,'container_id'=>0,'position'=>8]);
$chestSlot=array_values(array_filter($moved['state']['inventory_slots'],static function($slot)use($chestId){return $slot['id']===$chestId;}));
checkCraft(count($chestSlot)===1&&$chestSlot[0]['position']===8&&$moved['state']['containers'][0]===$container,'moving a filled chest preserves its identity, contents and durability');

// The same stale-source protection applies to a complete withdrawal into an empty cell.
$withdraw=['id'=>(int)$material['id'],'slot_id'=>$container['slots'][0]['id'],'quantity'=>20,'container_id'=>0,'position'=>1];
$withdrawn=$engine->command(9001,'full-stack-withdrawal','transfer',$withdraw);
$stale=$withdraw;$stale['position']=3;
try {
    $engine->command(9001,'stale-stack-withdrawal','transfer',$stale);
    throw new \RuntimeException('A stale withdrawal must not move the retrieved stack again.');
} catch(\yii\web\ConflictHttpException $error) {
    checkCraft(sameCraftState($withdrawn['state'],$engine->state(9001)),'exhausted chest source rejects another withdrawal without moving backpack items');
}
$replay=$engine->command(9001,'full-stack-withdrawal','transfer',$withdraw);
checkCraft($replay['replayed']&&sameCraftState($withdrawn['state'],$replay['state'])&&$s->quantities(9001)[(int)$material['id']]===20,'withdrawal replay returns success without duplicating materials');
class FailedTransferStorage extends \common\modules\craft\service\CraftStorage {
    public function insert(string $table,array $values): int {
        if($table==='craft_inventory')throw new \RuntimeException('transfer destination failure');
        return parent::insert($table,$values);
    }
}
$backpackSlot=array_values(array_filter($withdrawn['state']['inventory_slots'],static function($slot)use($material){return $slot['item_id']===(int)$material['id'];}))[0];
try {
    (new \common\modules\craft\service\Crafting(new FailedTransferStorage($db)))->command(9001,'failed-stack-transfer','transfer',['id'=>(int)$material['id'],'slot_id'=>$backpackSlot['id'],'quantity'=>20,'container_id'=>$chestId,'position'=>6]);
    throw new \LogicException('Transfer failure expected.');
} catch(\RuntimeException $error) {
    if($error->getMessage()!=='transfer destination failure')throw $error;
}
checkCraft(sameCraftState($withdrawn['state'],$engine->state(9001)),'destination write failure restores the exhausted source and chest durability');
$transferTx->rollBack();
