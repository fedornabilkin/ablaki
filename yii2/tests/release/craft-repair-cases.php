<?php
// Included by classic-craft on disposable SQLite, MySQL and PostgreSQL.
$repairTx = $db->beginTransaction();
try {
    $inventory = new \common\modules\world\modules\craft\models\domain\CraftInventory($s);
    $inventory->synchronize(9001);
    foreach (['classic-chest'=>1, 'classic-plank'=>12, 'classic-nails'=>12, 'classic-saw'=>1, 'classic-bench'=>1] as $code=>$quantity) $s->move(9001,$items[$code],$quantity);
    $slot = (new \yii\db\Query())->from('craft_inventory')->where(['user_id'=>9001,'item_id'=>$items['classic-chest']['id']])->one($db);
    $slotId = (int)$slot['id']; $itemId = (int)$slot['item_id'];
    $container = $inventory->container(9001,$slotId); $container['durability'] = 0; $s->insert('craft_container',$container);
    $repair = new \common\modules\world\modules\craft\models\domain\ChestRepair($s);
    $quote = $repair->quote(9001,$slotId);
    checkCraft(array_column($quote['materials'],'quantity')===[4,4] && count($quote['tools'])===1 && $quote['station']['name']!=='' && !$quote['reasons'],'full repair uses half the chest recipe plus its tool and station');
    $payload = ['id'=>$itemId,'slot_id'=>$slotId,'quantity'=>1];
    $before = $engine->state(9001);
    foreach ([0,-1,2,1.5,'1',null,[]] as $bad) rejectsCraft(function()use($engine,$payload,$bad){$engine->command(9001,'invalid-repair-quantity','repair',array_replace($payload,['quantity'=>$bad]));},'invalid repair quantities rejected');
    rejectsCraft(function()use($engine,$payload){$engine->command(9002,'foreign-repair-key','repair',$payload);},'another owner cannot repair a chest');
    checkCraft(sameCraftState($before,$engine->state(9001)),'invalid repair leaves inventory and durability unchanged');
    $fixed = $engine->command(9001,'first-chest-repair','repair',$payload);
    $fixedState = $fixed['state'];
    checkCraft($fixedState['containers'][0]['durability']===$container['max_durability'] && $fixedState['containers'][0]['repair']['tools'][0]['durability']===99,'repair restores chest and wears the retained tool slowly');
    checkCraft($fixedState['containers'][0]['repair']['station']['durability']===99,'repair also wears its station by one point');
    checkCraft($engine->command(9001,'first-chest-repair','repair',$payload)['replayed'] && sameCraftState($fixedState,$engine->state(9001)),'repair retry spends materials and tool durability once');
    rejectsCraft(function()use($engine,$payload){$engine->command(9001,'intact-chest-repair','repair',$payload);},'intact chest cannot consume repair materials');
    $db->createCommand()->update('craft_container',['durability'=>50],['id'=>$slotId])->execute();
    checkCraft(array_column($repair->quote(9001,$slotId)['materials'],'quantity')===[2,2],'half damage costs fewer materials');
    class FailedRepairStorage extends \common\modules\world\modules\craft\models\domain\CraftStorage {
        public function insert(string $table,array $values): int { if($table==='craft_event')throw new \RuntimeException('repair history failure');return parent::insert($table,$values); }
    }
    $before = $engine->state(9001);
    try { (new \common\modules\world\modules\craft\models\domain\Crafting(new FailedRepairStorage($db)))->command(9001,'failed-chest-repair','repair',$payload);throw new \LogicException('Expected failure'); }
    catch (\RuntimeException $error) { if($error->getMessage()!=='repair history failure')throw $error; }
    checkCraft(sameCraftState($before,$engine->state(9001)),'late repair failure rolls back materials, tool wear and chest durability');
    $db->createCommand()->update('craft_tool_wear',['wear'=>99],['user_id'=>9001,'item_id'=>$items['classic-saw']['id']])->execute();
    $db->createCommand()->update('craft_tool_wear',['wear'=>99],['user_id'=>9001,'item_id'=>$items['classic-bench']['id']])->execute();
    $engine->command(9001,'last-tool-repair-key','repair',$payload);
    checkCraft(empty($s->quantities(9001)[$items['classic-saw']['id']]),'exhausted tool is consumed after its last successful repair');
    checkCraft(empty($s->quantities(9001)[$items['classic-bench']['id']]),'exhausted station is consumed after its last successful repair');
    checkCraft(!$repair->quote(9001,$slotId)['tools'][0]['available']&&!$repair->quote(9001,$slotId)['station']['available'],'quote flags missing tool and station individually');
    $db->createCommand()->update('craft_container',['durability'=>99],['id'=>$slotId])->execute();
    $before = $engine->state(9001);
    rejectsCraft(function()use($engine,$payload){$engine->command(9001,'missing-tool-repair','repair',$payload);},'repair requires the recipe tool');
    checkCraft(sameCraftState($before,$engine->state(9001)),'missing tool does not spend remaining resources');
    $s->move(9001,$items['classic-saw'],1);
    rejectsCraft(function()use($engine,$payload){$engine->command(9001,'missing-station-repair','repair',$payload);},'repair requires the recipe station');
    $s->move(9001,$items['classic-bench'],1);
    $s->move(9001,$items['classic-plank'],-$s->quantities(9001)[$items['classic-plank']['id']]);
    rejectsCraft(function()use($engine,$payload){$engine->command(9001,'missing-material-repair','repair',$payload);},'repair requires enough materials even for small damage');
    checkCraft(!$repair->quote(9001,$slotId)['materials'][0]['available']&&$repair->quote(9001,$slotId)['station']['available'],'quote flags only missing materials while the replacement station remains available');
    $state=$engine->state(9001);
    checkCraft($state['gather_available_at']>$state['server_time'] && $state['gather_available_at']-$state['server_time']<=86400,'gather deadline is the next Moscow midnight');
} finally { $repairTx->rollBack(); }
