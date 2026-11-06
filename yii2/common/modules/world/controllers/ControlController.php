<?php
namespace common\modules\world\controllers;

use common\modules\world\admin\ControlCatalog;
use common\modules\world\models\admin\{AdminAccess, WorldControlReport, WorldEmergencyEdit};
use common\modules\world\support\GameError;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\web\{Controller, HttpException};

/** One permission boundary and one audited correction flow for the world control center. */
final class ControlController extends Controller
{
    public function beforeAction($action) { AdminAccess::requirePermission(); return parent::beforeAction($action); }
    public function behaviors(): array { return ['verbs'=>['class'=>\yii\filters\VerbFilter::class,'actions'=>['index'=>['GET'],'view'=>['GET'],'edit'=>['GET','POST'],'apply'=>['POST']]]]; }
    public function getViewPath() { return dirname(__DIR__).'/views/control'; }
    public function actionIndex(string $group='overview', string $entity='', string $q='', array $filter=[])
    {
        $catalog=new ControlCatalog(Yii::$app->db); $entries=$catalog->entries();
        if(!isset(ControlCatalog::groups()[$group])) throw new HttpException(404,'Раздел не найден.');
        $provider=null; $columns=[]; $fields=[];
        if($entity!=='') {
            $schema=$catalog->schema($entity); $group=$entries[$entity]['group'];
            if(mb_strlen($q)>120) throw new HttpException(422,'Слишком длинный запрос.');
            $query=(new Query())->from($entity);
            foreach($schema->columns as $name=>$column) if(in_array($name,$schema->primaryKey,true) || str_ends_with($name,'_id') || in_array($name,['status','node_type','kind','role','channel','enabled'],true)) $fields[]=$name;
            foreach($filter as $name=>$value) {
                if(!in_array($name,$fields,true) || !is_scalar($value) || strlen((string)$value)>120) throw new HttpException(422,'Некорректный фильтр.');
                if($value!=='') $query->andWhere([$name=>$value]);
            }
            if(trim($q)!=='') {
                $where=['or'];
                foreach(array_intersect(['name','code','status','kind','comment','reason','role'],array_keys($schema->columns)) as $name) $where[]=['like',$name,trim($q)];
                if(ctype_digit($q) && isset($schema->columns['id'])) $where[]=['id'=>$q];
                $query->andWhere(count($where)>1 ? $where : '0=1');
            }
            $columns=array_values(array_filter(array_keys($schema->columns),static fn($name)=>!str_ends_with($name,'_json')));
            $provider=new ActiveDataProvider(['query'=>$query,'db'=>Yii::$app->db,'pagination'=>['pageSize'=>25],
                'sort'=>['attributes'=>$columns,'defaultOrder'=>$schema->primaryKey ? array_fill_keys($schema->primaryKey,SORT_DESC) : []]]);
        }
        $report=$entity==='' ? (new WorldControlReport(Yii::$app->db))->report() : null;
        return $this->render('index',compact('group','entity','q','filter','entries','provider','columns','fields','report'));
    }
    public function actionView(string $entity,array $key)
    {
        $service=new WorldEmergencyEdit(Yii::$app->db);
        try { $row=$service->read($entity,$key); $key=array_intersect_key($row,$key); } catch(GameError $e) { throw new HttpException($e->status,$e->getMessage()); }
        $history=Yii::$app->db->schema->getTableSchema('world_admin_correction') ? (new Query())->from('world_admin_correction')
            ->where(['entity'=>$entity,'key_json'=>\common\modules\world\support\CanonicalJson::encode($key)])
            ->orderBy(['id'=>SORT_DESC])->limit(15)->all() : [];
        return $this->render('view',compact('entity','key','row','history'));
    }
    public function actionEdit(string $entity,array $key)
    {
        $service=new WorldEmergencyEdit(Yii::$app->db); $row=$service->read($entity,$key);
        if(ControlCatalog::immutable($entity)) throw new HttpException(422,'Журнал доступен только для чтения.');
        $error=null; $preview=null; $token=null;
        if(Yii::$app->request->isPost) {
            try {
                $values=Yii::$app->request->post('values',[]); $selected=Yii::$app->request->post('selected',[]); $nulls=Yii::$app->request->post('nulls',[]);
                if(!is_array($values) || !is_array($selected) || !is_array($nulls)) throw new HttpException(422,'Некорректная форма.');
                $values=array_intersect_key($values,$selected);
                foreach($values as $name=>&$value) if(isset($nulls[$name])) $value=null;
                unset($value);
                $preview=$service->prepare((int)Yii::$app->user->id,$entity,$key,$values,(string)Yii::$app->request->post('comment',''));
                if(!hash_equals(WorldEmergencyEdit::digest($row),(string)Yii::$app->request->post('version',''))) throw new GameError('RECORD_CHANGED','Запись изменилась. Обновите страницу.',409);
                $token=bin2hex(random_bytes(16));
                // A single pending preview per browser; no client-supplied before/after values on apply.
                Yii::$app->session->set('world-correction',['actor'=>(int)Yii::$app->user->id,'until'=>time()+900,'token'=>$token,'record'=>$preview]);
            } catch(GameError | \JsonException | \InvalidArgumentException $e) { $error=$e->getMessage(); Yii::$app->response->statusCode=$e instanceof GameError ? $e->status : 422; }
        }
        return $this->render('edit',compact('entity','key','row','error','preview','token'));
    }
    public function actionApply()
    {
        $pending=Yii::$app->session->get('world-correction'); $token=(string)Yii::$app->request->post('token','');
        if(!$pending || $pending['actor']!==(int)Yii::$app->user->id || $pending['until']<time() || !hash_equals($pending['token'],$token)) throw new HttpException(409,'Подтверждение устарело. Подготовьте исправление заново.');
        try { (new WorldEmergencyEdit(Yii::$app->db))->execute((int)Yii::$app->user->id,$pending['record'],$token); }
        catch(GameError $e) { throw new HttpException($e->status,$e->getMessage()); }
        Yii::$app->session->setFlash('success','Исправление сохранено вместе с комментарием и прежними значениями.');
        return $this->redirect(['view','entity'=>$pending['record']['entity'],'key'=>$pending['record']['key']]);
    }
}
