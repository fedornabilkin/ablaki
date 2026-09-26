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
    public function inventory(): array
    {
        $meta=(new Query())->from('craft_meta')->where(['id'=>1])->one($this->s->db);$settings=[];
        foreach(['slot_price'=>10,'elixir_slots'=>5,'elixir_days'=>7,'chest_slots'=>10,'chest_durability'=>100,'chest_wear'=>1] as $key=>$default)$settings[$key]=(int)($meta[$key]??$default);
        return $settings;
    }
    public function setInventory(array $values,int $actor): void
    {
        if($actor<1)throw new \InvalidArgumentException('An administrator is required.');
        $clean=[];
        foreach(['slot_price'=>[0,1000000],'elixir_slots'=>[1,30],'elixir_days'=>[1,365],'chest_slots'=>[1,100],'chest_durability'=>[1,100000],'chest_wear'=>[0,100000]] as $field=>$range) {
            $value=$values[$field]??null;
            if(!is_string($value)||!ctype_digit($value)||(int)$value<$range[0]||(int)$value>$range[1])throw new \yii\web\UnprocessableEntityHttpException('Некорректная настройка: '.$field);
            $clean[$field]=(int)$value;
        }
        $this->s->db->transaction(function()use($clean,$actor){
            $this->s->lock('craft_meta',['id'=>1]);
            $this->s->db->createCommand()->update('craft_meta',$clean+['charges_updated_by'=>$actor,'charges_updated_at'=>time()],['id'=>1])->execute();
            \Yii::info(['actor'=>$actor,'inventory'=>$clean],'craft.settings');
        });
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
