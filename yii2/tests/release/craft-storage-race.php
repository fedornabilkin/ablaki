<?php
use yii\db\Query;
function storageRace(string $action,array $payloads,bool $same=false): int {
    $children=[];
    foreach($payloads as $i=>$body) {
        $key='storage-race-'.$action.'-'.($same?'same':$i);
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/classic-craft.php').' worker '.escapeshellarg($key).' storage '.escapeshellarg($action).' '.escapeshellarg(base64_encode(json_encode($body)));
        $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new \RuntimeException('Cannot start storage worker.');$children[]=[$process,$pipes];
    }
    releaseWorkers($children);$ok=0;
    foreach($children as list($process,$pipes)) {
        $out=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0||!in_array($out,['ok','rejected'],true))throw new \RuntimeException($out.$err);
        if($out==='ok')$ok++;
    }
    return $ok;
}
$db->createCommand()->delete('craft_command',['user_id'=>9001])->execute();
$db->createCommand()->update('craft_capacity',['permanent_slots'=>20],['user_id'=>9001])->execute();
$db->createCommand()->update('persone',['credit'=>20],['user_id'=>9001])->execute();
checkCraft(storageRace('buy_slots',array_fill(0,6,['quantity'=>1,'unit_price'=>10,'total_price'=>10]))===1&&$engine->state(9001)['permanent_slots']===21&&$engine->state(9001)['credit']===10.0,'parallel purchases reject stale prices after the first slot');
rejectsCraft(function()use($engine){$engine->command(9001,'race-next-slot-no-credit','buy_slots',['quantity'=>1,'unit_price'=>20,'total_price'=>20]);},'next rising price cannot overspend the remaining balance');
$db->transaction(function()use($db,$s,$items){
    $s->lock('craft_meta',['id'=>1]);$s->lock('persone',['user_id'=>9001]);
    $db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();$db->createCommand()->delete('craft_container',['user_id'=>9001])->execute();
    $s->move(9001,$items['classic-log'],100);$s->move(9001,$items['classic-chest'],1);
});
$chestId=$engine->state(9001)['containers'][0]['id'];
$source=(int)(new Query())->select('id')->from('craft_inventory')->where(['user_id'=>9001,'item_id'=>$log])->scalar($db);
$bodies=[];for($i=1;$i<=6;$i++)$bodies[]=['id'=>$log,'slot_id'=>$source,'quantity'=>20,'container_id'=>$chestId,'position'=>$i];
$transferred=storageRace('transfer',$bodies);
checkCraft($transferred===5,'parallel chest transfers consume the source stack only once (successful: '.$transferred.', expected: 5)');
$container=$engine->state(9001)['containers'][0];
checkCraft($container['durability']===95&&array_sum(array_column($container['slots'],'quantity'))===100,'parallel deposits preserve all materials and exact wear');
$body=['id'=>$log,'slot_id'=>$container['slots'][0]['id'],'quantity'=>20,'container_id'=>0,'position'=>1];
checkCraft(storageRace('transfer',array_fill(0,6,$body),true)===6&&$s->quantities(9001)[$log]===20,'same-key parallel withdrawal replays without duplicate materials');
$db->transaction(function()use($db,$s,$items,$chestId){
    $s->lock('craft_meta',['id'=>1]);$s->lock('persone',['user_id'=>9001]);
    foreach(['classic-plank'=>12,'classic-nails'=>12,'classic-saw'=>1,'classic-bench'=>1] as $code=>$quantity)$s->move(9001,$items[$code],$quantity);
    $db->createCommand()->update('craft_container',['durability'=>0],['id'=>$chestId])->execute();
});
$repairBody=['id'=>(int)$items['classic-chest']['id'],'slot_id'=>$chestId,'quantity'=>1];
$beforeRepair=$s->quantities(9001);
$contents=$engine->state(9001)['containers'][0]['slots'];
checkCraft(storageRace('repair',array_fill(0,6,$repairBody))===1,'parallel distinct repairs restore a damaged chest once');
$afterRepair=$s->quantities(9001);
$fixed=$engine->state(9001)['containers'][0];
checkCraft($beforeRepair[$items['classic-plank']['id']]-$afterRepair[$items['classic-plank']['id']]===4
    && $beforeRepair[$items['classic-nails']['id']]-$afterRepair[$items['classic-nails']['id']]===4
    && $fixed['durability']===100 && $fixed['repair']['tools'][0]['durability']===99 && $fixed['repair']['station']['durability']===99 && $fixed['slots']===$contents,
    'parallel repair spends one set of materials and preserves stored items');
$db->createCommand()->update('craft_container',['durability'=>50],['id'=>$chestId])->execute();
checkCraft(storageRace('repair',array_fill(0,6,$repairBody),true)===6,'parallel same-key repairs all replay the successful result');
$replayed=$engine->state(9001)['containers'][0];
checkCraft($afterRepair[$items['classic-plank']['id']]-$s->quantities(9001)[$items['classic-plank']['id']]===2
    && $replayed['durability']===100 && $replayed['repair']['tools'][0]['durability']===98 && $replayed['repair']['station']['durability']===98,
    'parallel repair retries spend materials and tool wear once');
