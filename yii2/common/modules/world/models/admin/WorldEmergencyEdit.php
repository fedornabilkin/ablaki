<?php
namespace common\modules\world\models\admin;

use common\modules\world\admin\ControlCatalog;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage};
use common\modules\world\support\{CanonicalJson, GameError, Locks};
use yii\db\{Connection, Expression, Query};

/** Explicit, audited repair of existing state; never accepts SQL or a table outside the world catalog. */
final class WorldEmergencyEdit
{
    public function __construct(private readonly Connection $db) {}
    public static function digest(array $row): string { return hash('sha256',CanonicalJson::encode($row)); }
    public function read(string $entity,array $key): array
    {
        $schema=(new ControlCatalog($this->db))->schema($entity);
        $fields=array_keys($key); sort($fields); $primary=$schema->primaryKey; sort($primary);
        if(!$primary || $fields!==$primary) throw new GameError('INVALID_RECORD_KEY','Выберите запись по полному ключу.',422);
        foreach($key as $value) if(!is_scalar($value) || strlen((string)$value)>128) throw new GameError('INVALID_RECORD_KEY','Некорректный ключ записи.',422);
        $row=(new Query())->from($entity)->where($key)->one($this->db);
        if(!$row) throw new GameError('RECORD_NOT_FOUND','Запись не найдена.',404);
        return $row;
    }
    public function prepare(int $actor,string $entity,array $key,array $values,string $comment): array
    {
        $this->authorize($actor);
        if(ControlCatalog::immutable($entity)) throw new GameError('IMMUTABLE_HISTORY','Журнал неизменяем. Исправляйте состояние отдельной операцией.',422);
        $comment=trim($comment);
        if(mb_strlen($comment)<5 || mb_strlen($comment)>2000) throw new GameError('COMMENT_REQUIRED','Опишите причину исправления: от 5 до 2000 символов.',422);
        $row=$this->read($entity,$key); $schema=$this->db->schema->getTableSchema($entity); $changes=[];
        foreach($values as $field=>$value) {
            if(!isset($schema->columns[$field]) || in_array($field,$schema->primaryKey,true) || in_array($field,['revision','operation_id'],true)) throw new GameError('READ_ONLY_FIELD','Недоступное поле: '.$field,422);
            $column=$schema->columns[$field];
            if($value===null) { if(!$column->allowNull) throw new GameError('REQUIRED_FIELD','Поле обязательно: '.$field,422); }
            elseif(!is_scalar($value)) throw new GameError('INVALID_FIELD','Неверное значение: '.$field,422);
            elseif(in_array($column->type,['integer','smallint','bigint','tinyint','boolean'],true)) {
                if(!preg_match('/^-?\d+$/D',(string)$value) || filter_var($value,FILTER_VALIDATE_INT)===false) throw new GameError('INVALID_NUMBER','Нужно целое число: '.$field,422);
                $value=(int)$value;
                if($column->type==='boolean' && !in_array($value,[0,1],true)) throw new GameError('INVALID_BOOLEAN','Выберите 0 или 1.',422);
            } elseif($column->type==='decimal' && (int)$column->scale<=4) $value=Money::parse((string)$value)->decimal();
            else {
                $value=(string)$value;
                if(strlen($value)>1000000 || ($column->size && in_array($column->type,['string','char'],true) && mb_strlen($value)>$column->size)) throw new GameError('FIELD_TOO_LONG','Слишком длинное значение: '.$field,422);
                if(str_ends_with($field,'_json')) json_decode($value,true,64,JSON_THROW_ON_ERROR);
            }
            if(($value===null)!==($row[$field]===null) || (string)$value!==(string)$row[$field]) $changes[$field]=$value;
        }
        if(!$changes) throw new GameError('NO_CHANGES','Нет изменений.',422);
        $after=array_replace($row,$changes); $this->validate($entity,$row,$after,$changes);
        return ['entity'=>$entity,'key'=>array_intersect_key($row,$key),'before'=>$row,'changes'=>$changes,'comment'=>$comment,'version'=>self::digest($row)];
    }
    private function validate(string $entity,array $before,array $after,array $changes): void
    {
        $schema=$this->db->schema->getTableSchema($entity);
        foreach($schema->foreignKeys as $foreign) {
            $table=$foreign[0]; unset($foreign[0]); $where=[]; $complete=true;
            foreach($foreign as $local=>$remote) { if($after[$local]===null){$complete=false;break;} $where[$remote]=$after[$local]; }
            if($complete && !(new Query())->from($table)->where($where)->exists($this->db)) throw new GameError('RELATED_RECORD_MISSING','Связанная запись не найдена: '.$table,422);
        }
        if($entity==='world_npc_funding') {
            if($after['interval_seconds']<3600 || $after['interval_seconds']>604800 || $after['share_bps']<0 || $after['share_bps']>10000 || $after['carry']<0 || $after['carry']>=10000) throw new GameError('INVALID_FUNDING_RULE','Интервал — не меньше часа; доля — от 0 до 100%.',422);
            foreach(['daily_limit_cr','target_cr','eligible_cr','funded_cr','sales_cr','day_spent_cr'] as $field) if(Money::parse((string)$after[$field])->isNegative()) throw new GameError('NEGATIVE_AMOUNT','Суммы NPC не могут быть отрицательными.',422);
        }
        if($entity==='economy_account') {
            if(array_diff(array_keys($changes),['amount'])) throw new GameError('ACCOUNT_STRUCTURE_LOCKED','В счёте можно исправить сумму. Назначение и резервы меняются профильными операциями.',422);
            if(Money::parse((string)$after['amount'])->compare(Money::parse((string)$after['reserved']))<0) throw new GameError('RESERVED_FUNDS','Сумма не может быть меньше принятых обязательств.',422);
        }
        if($entity==='world_node' && array_intersect(array_keys($changes),['parent_id','root_id','hierarchy_level','template_id','node_type'])) throw new GameError('STRUCTURAL_CHANGE','Для изменения дерева используйте редактор объекта: он проверяет связи и уровни.',422);
        if($entity==='world_node') {
            $model=\common\modules\world\models\Node::findOne($before['id']); $model->setAttributes($changes,false);
            if(!$model->validate()) throw new GameError('INVALID_NODE',implode(' ',$model->getFirstErrors()),422);
        }
        if($entity==='craft_inventory') {
            if(array_diff(array_keys($changes),['item_quantity']) || $after['item_quantity']<0) throw new GameError('INVENTORY_STRUCTURE','Здесь исправляется количество; перемещение выполняйте через хранилище.',422);
            $item=(new Query())->from('craft_item')->where(['id'=>$after['item_id']])->one($this->db);
            if(!in_array($item['kind'] ?? '',['material','product'],true)) throw new GameError('INSTANCE_REQUIRED','Оборудование исправляется в карточке экземпляра.',422);
            (new CanonicalInventory(new CraftStorage($this->db)))->correctionPlan($before,(int)$after['item_quantity']);
        }
    }
    private function authorize(int $actor): void
    {
        if(!\Yii::$app->authManager->checkAccess($actor,'p-admin')) throw new GameError('ADMIN_REQUIRED','Нужны права администратора.',403);
    }
    public function execute(int $actor,array $record,string $operation): array
    {
        $this->authorize($actor);
        if(!preg_match('/^[a-f0-9]{32}$/D',$operation)) throw new GameError('INVALID_OPERATION','Некорректный ключ исправления.',422);
        // Validate the catalog and primary key before passing any identifier to a query builder.
        $this->read($record['entity'],$record['key']);
        return $this->db->transaction(function() use($actor,$record,$operation): array {
            $locks=new Locks($this->db); $locks->row('craft_meta',['id'=>1]);
            $owners=[$actor]; foreach(['user_id','owner_user_id'] as $field) if(!empty($record['before'][$field])) $owners[]=(int)$record['before'][$field];
            $locks->owners($owners,false); $locks->row('world_registry',['id'=>1]);
            $old=(new Query())->from('world_admin_correction')->where(['operation_id'=>$operation])->one($this->db);
            if($old) {
                if((int)$old['author_id']!==$actor || !hash_equals($old['request_hash'],self::digest($record))) throw new GameError('CORRECTION_CONFLICT','Ключ исправления уже использован.',409);
                return $old;
            }
            $before=$locks->row($record['entity'],$record['key']);
            if(!$before || !hash_equals(self::digest($before),$record['version'])) throw new GameError('RECORD_CHANGED','Запись изменилась. Откройте её заново.',409);
            $checked=$this->prepare($actor,$record['entity'],$record['key'],$record['changes'],$record['comment']);
            $changes=$checked['changes'];
            if(array_key_exists('revision',$before)) $changes['revision']=new Expression('[[revision]]+1');
            $this->db->createCommand()->insert('game_operation',['id'=>$operation,'user_id'=>$actor,'type'=>'world.admin.correction','created_at'=>time()])->execute();
            if($record['entity']==='craft_inventory') {
                $store=new CraftStorage($this->db); $store->operationId=$operation;
                (new CanonicalInventory($store))->correctQuantity($before,(int)$changes['item_quantity']);
            } else {
                if($record['entity']==='world_node' && isset($changes['name'])) $changes['label']=trim($changes['name']);
                if($this->db->createCommand()->update($record['entity'],$changes,$record['key'])->execute()!==1) throw new \RuntimeException('Correction write failed.');
            }
            $after=$this->read($record['entity'],$record['key']);
            if($record['entity']==='economy_account') {
                $delta=Money::parse((string)$after['amount'])->subtract(Money::parse((string)$before['amount']));
                $this->db->createCommand()->insert('economy_transfer',['operation_id'=>$operation,'line_code'=>'admin-adjustment','kind'=>'admin_correction',
                    'source_account_id'=>$delta->isNegative()?$before['id']:null,'destination_account_id'=>$delta->isNegative()?null:$before['id'],
                    'amount'=>($delta->isNegative()?Money::parse('0')->subtract($delta):$delta)->decimal(),
                    'source_after'=>$delta->isNegative()?$after['amount']:'0.0000','destination_after'=>$delta->isNegative()?'0.0000':$after['amount'],
                    'purpose'=>mb_substr($record['comment'],0,255),'created_at'=>time()])->execute();
            }
            $this->db->createCommand()->insert('world_admin_correction',['author_id'=>$actor,'operation_id'=>$operation,'request_hash'=>self::digest($record),'entity'=>$record['entity'],
                'key_json'=>CanonicalJson::encode($record['key']),'before_json'=>CanonicalJson::encode($before),'after_json'=>CanonicalJson::encode($after),
                'comment'=>$record['comment'],'created_at'=>time()])->execute();
            $id=(int)$this->db->getLastInsertID();
            $this->db->createCommand()->update('world_registry',['content_revision'=>new Expression('[[content_revision]]+1')],['id'=>1])->execute();
            return ['id'=>$id,'entity'=>$record['entity']];
        });
    }
}
