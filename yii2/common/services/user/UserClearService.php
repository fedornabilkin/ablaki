<?php
namespace common\services\user;

use common\models\user\User;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Connection;
use yii\db\Query;

/** Only abandoned, unconfirmed registrations without property or related activity are removed. */
class UserClearService
{
    private $db;
    private $schemas;
    public function __construct(?Connection $db=null) { $this->db=$db??Yii::$app->db; }
    public function clearQuery(): ActiveQuery
    {
        $query=User::find()->alias('u')->where(['<','u.created_at',time()-3*86400])->andWhere(['>','u.created_at',1483228800]);
        $columns=$this->db->schema->getTableSchema('user')->columns;$confirmed=false;
        foreach(['confirmed_at','mail_approve'] as $column)if(isset($columns[$column])){$query->andWhere(['or',['u.'.$column=>null],['u.'.$column=>0]]);$confirmed=true;}
        if(!$confirmed)$query->andWhere('1=0');
        return $query;
    }
    public function clear(): void
    {
        $cursor=0;$removed=0;
        do {
            $ids=$this->clearQuery()->select('u.id')->andWhere(['>','u.id',$cursor])->orderBy(['u.id'=>SORT_ASC])->limit(500)->column($this->db);
            foreach($ids as $id) {
                $cursor=(int)$id;
                try { if($this->removeDead((int)$id))$removed++; }
                catch(\Throwable $error) { Yii::warning(['user_id'=>(int)$id,'error_class'=>get_class($error)],'user.cleanup'); }
                if($removed>=500)return;
            }
        }while(count($ids)===500);
    }
    public function removeDead(int $id): bool
    {
        return $this->db->transaction(function()use($id){
            // Activity, rating and cleanup serialize on the user, then its account.
            $ledger=new CreditLedger($this->db);$user=$ledger->lock('user',['id'=>$id]);
            if(!$user||!$this->deadRegistration($user))return false;
            $person=$ledger->lock('persone',['user_id'=>$id]);
            if($person)foreach(['rating','credit','balance','balance_in','balance_out','bonus_count','refovod'] as $field)if(isset($person[$field])&&(!is_numeric($person[$field])||(float)$person[$field]!=0))return false;
            if($this->referenced($id))return false;
            foreach(['token','profile','persone'] as $table)if($this->db->schema->getTableSchema($table))$this->db->createCommand()->delete($table,['user_id'=>$id])->execute();
            if($this->db->createCommand()->delete('user',['id'=>$id])->execute()!==1)throw new \RuntimeException('Registration delete failed.');
            Yii::info(['user_id'=>$id,'reason'=>'abandoned_unconfirmed_registration'],'user.cleanup');
            return true;
        });
    }
    private function deadRegistration(array $user): bool
    {
        $created=(int)($user['created_at']??0);
        if($created<=1483228800||$created>=time()-3*86400)return false;
        $confirmation=false;
        foreach(['confirmed_at','mail_approve'] as $field)if(array_key_exists($field,$user)){$confirmation=true;if((int)$user[$field]>0)return false;}
        if(!$confirmation)return false;
        // Registration may sign the user in once. Later visits preserve the account.
        if((int)($user['last_login_at']??0)>$created+300)return false;
        $latest=UserActivity::timestamp($user['latest_activity']??null);
        if($latest!==null&&$latest>$created+300)return false;
        if(!empty($user['blocked_at']))return false;
        return true;
    }
    private function referenced(int $id): bool
    {
        if($this->schemas===null)$this->schemas=$this->db->schema->getTableSchemas();
        foreach($this->schemas as $schema) {
            $table=$schema->name;
            if($table==='user')continue;
            if($table==='persone') {
                if(isset($schema->columns['refovod'])&&(new Query())->from($table)->where(['refovod'=>$id])->exists($this->db))return true;
                continue;
            }
            if($table==='token') {
                if((new Query())->from($table)->where(['user_id'=>$id])->andWhere(['>=','created_at',time()-86400])->exists($this->db))return true;
                continue;
            }
            if($table==='profile') {
                $profile=(new Query())->from($table)->where(['user_id'=>$id])->one($this->db);
                if($profile)foreach(['name','public_email','location','website','bio'] as $field)if(!empty($profile[$field]))return true;
                continue;
            }
            $columns=[];
            foreach(['user_id','user_gamer','user_buyer','user_seller','recipient_id','sender_id','owner_id','author_id','created_by','updated_by','refovod'] as $field)if(isset($schema->columns[$field]))$columns[$field]=true;
            foreach($schema->foreignKeys as $foreign)if(($foreign[0]??null)==='user')foreach($foreign as $local=>$remote)if($local!==0&&$remote==='id')$columns[$local]=true;
            if(!$columns)continue;$where=['or'];foreach(array_keys($columns) as $column)$where[]=[$column=>$id];
            if((new Query())->from($table)->where($where)->exists($this->db))return true;
        }
        return false;
    }
}
