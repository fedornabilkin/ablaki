<?php
namespace common\modules\craft\service;

use yii\db\Query;

class CraftSettings
{
    private $s;
    public function __construct(CraftStorage $store) { $this->s=$store; }
    public function chargeCredits(): bool
    {
        $meta=(new Query())->from('craft_meta')->where(['id'=>1])->one($this->s->db);
        return (int)($meta['charge_credits']??0)===1;
    }
    public function setChargeCredits(bool $enabled,int $actor): void
    {
        if($actor<1)throw new \InvalidArgumentException('An administrator is required.');
        $this->s->db->transaction(function()use($enabled,$actor){
            $meta=$this->s->lock('craft_meta',['id'=>1]);
            if(!array_key_exists('charge_credits',$meta))throw new \yii\web\ConflictHttpException('Сначала примените миграцию настроек крафта.');
            if((int)$meta['charge_credits']===(int)$enabled)return;
            $changed=$this->s->db->createCommand()->update('craft_meta',[
                'charge_credits'=>(int)$enabled,'charges_updated_by'=>$actor,'charges_updated_at'=>time(),
            ],['id'=>1])->execute();
            if($changed!==1)throw new \RuntimeException('Craft settings write failed.');
        });
    }
}
