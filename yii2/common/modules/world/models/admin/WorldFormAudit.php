<?php
namespace common\modules\world\models\admin;

use common\modules\world\support\{CanonicalJson, Locks};
use yii\db\ActiveRecord;

/** Keeps the established domain form validation and records its accepted change in the same transaction. */
final class WorldFormAudit
{
    public static function save(ActiveRecord $model,int $author,string $comment): bool
    {
        $db=$model::getDb();
        if(!$db->schema->getTableSchema('world_admin_correction')) return $model->save();
        $comment=trim($comment);
        if(mb_strlen($comment)<5 || mb_strlen($comment)>2000) { $model->addError($model::primaryKey()[0],'Укажите причину изменения: от 5 до 2000 символов.'); return false; }
        return $db->transaction(static function() use($db,$model,$author,$comment): bool {
            $locks=new Locks($db); $locks->row('craft_meta',['id'=>1]); $locks->owners([$author],false); $locks->row('world_registry',['id'=>1]);
            $before=$model->isNewRecord ? [] : ($locks->row($model::tableName(),$model->getPrimaryKey(true)) ?: []);
            if(!$model->save()) return false;
            $after=$model->getAttributes();
            $operation=bin2hex(random_bytes(16));
            $db->createCommand()->insert('game_operation',['id'=>$operation,'user_id'=>$author,'type'=>'world.admin.form','created_at'=>time()])->execute();
            $db->createCommand()->insert('world_admin_correction',['author_id'=>$author,'operation_id'=>$operation,'request_hash'=>WorldEmergencyEdit::digest([$before,$after,$comment]),'entity'=>$model::tableName(),
                'key_json'=>CanonicalJson::encode($model->getPrimaryKey(true)),'before_json'=>CanonicalJson::encode($before),'after_json'=>CanonicalJson::encode($after),
                'comment'=>$comment,'created_at'=>time()])->execute();
            return true;
        });
    }
}
