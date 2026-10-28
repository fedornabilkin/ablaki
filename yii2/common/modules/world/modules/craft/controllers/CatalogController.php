<?php
namespace common\modules\world\modules\craft\controllers;
use common\modules\world\modules\craft\models\domain\CraftCatalog;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\CraftSettings;
use Yii;
use yii\web\UploadedFile;
use yii\web\UnprocessableEntityHttpException;

class CatalogController extends AdminController
{
    public function behaviors() { return ['verbs'=>['class'=>\yii\filters\VerbFilter::class,'actions'=>['preview'=>['POST'],'import'=>['POST'],'settings'=>['POST'],'edit'=>['GET','POST'],'export'=>['GET'],'economy'=>['GET','POST']]]]; }
    private function catalog(): CraftCatalog { return new CraftCatalog(new CraftStorage(Yii::$app->db)); }
    private function settings(): CraftSettings { return new CraftSettings(new CraftStorage(Yii::$app->db)); }
    public function actionIndex() { return $this->render('index',['catalog'=>$this->catalog()->export(),'error'=>'','chargeCredits'=>$this->settings()->chargeCredits(),'inventorySettings'=>$this->settings()->inventory()]); }
    public function actionEconomy()
    {
        $db = Yii::$app->db; $request = Yii::$app->request;
        $revision = $request->get('revision', '0');
        if ((!is_string($revision) && !is_int($revision)) || !preg_match('/^[0-9]{1,9}$/D', (string)$revision)) throw new UnprocessableEntityHttpException('Выберите версию профиля.');
        $current = \common\modules\world\models\domain\EconomyRuntime::current($db);
        $profile = $current ? $current['profile'] : \common\modules\world\models\domain\EconomyProfile::load();
        if ((int)$revision) {
            $historical = (new \yii\db\Query())->from('world_economy_profile')->where(['id' => (int)$revision])->one($db);
            if (!$historical) throw new \yii\web\NotFoundHttpException('Версия не найдена.');
            $profile = json_decode($historical['config_json'], true, 64, JSON_THROW_ON_ERROR);
        }
        $document = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $error = ''; $preview = null; $reason = '';
        if ($request->isPost) {
            try {
                $document = $request->post('document', ''); $reason = $request->post('reason', '');
                if (!is_string($document) || strlen($document) > 2097152 || !is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) throw new \InvalidArgumentException('Нужны JSON до 2 МБ и причина до 500 символов.');
                $profile = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($profile)) throw new \InvalidArgumentException('Ожидается объект JSON.');
                $publisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db);
                if ($request->post('publish') === '1') {
                    $digest = $request->post('digest');
                    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new \InvalidArgumentException('Сначала проверьте изменения.');
                    $result = $publisher->publish($profile, $digest, (int)Yii::$app->user->id, $reason);
                    Yii::$app->session->setFlash('success', $result['changed'] ? 'Опубликована версия ' . $result['version'] . '. Принятые операции сохранены.' : 'Этот профиль уже действует.');
                    return $this->redirect(['economy']);
                }
                $preview = $publisher->preview($profile);
            } catch (\common\modules\world\support\GameError $failure) { Yii::$app->response->statusCode = $failure->status; $error = $failure->getMessage(); }
            catch (\JsonException $failure) { Yii::$app->response->statusCode = 422; $error = 'Некорректный JSON.'; }
            catch (\InvalidArgumentException $failure) { Yii::$app->response->statusCode = 422; $error = $failure->getMessage(); }
        }
        $page = $request->get('page', '1');
        if ((!is_string($page) && !is_int($page)) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string)$page)) throw new UnprocessableEntityHttpException('Некорректная страница.');
        $history = (new \yii\db\Query())->select(['id', 'version', 'author_user_id', 'reason', 'created_at'])->from('world_economy_profile')->orderBy(['version' => SORT_DESC])->limit(20)->offset(((int)$page - 1) * 20)->all($db);
        $total = (int)(new \yii\db\Query())->from('world_economy_profile')->count('*', $db);
        return $this->render('economy', compact('current', 'document', 'reason', 'preview', 'error', 'history', 'page', 'total'));
    }
    public function actionSettings()
    {
        $enabled=Yii::$app->request->post('charge_credits');
        if(!in_array($enabled,['0','1'],true))throw new UnprocessableEntityHttpException('Неверное значение переключателя.');
        $inventory=Yii::$app->request->post('inventory',[]);
        if(!is_array($inventory))throw new UnprocessableEntityHttpException('Некорректные настройки инвентаря.');
        Yii::$app->db->transaction(function()use($enabled,$inventory){
            $this->settings()->setInventory($inventory,(int)Yii::$app->user->id);
            $this->settings()->setChargeCredits($enabled==='1',(int)Yii::$app->user->id);
        });
        Yii::info(['actor'=>Yii::$app->user->id,'charge_credits'=>$enabled==='1'],'craft.settings');
        Yii::$app->session->setFlash('success',$enabled==='1'?'Списание кредитов за крафт включено.':'Списание кредитов за крафт выключено.');
        return $this->redirect(['index']);
    }
    public function actionExport()
    {
        return Yii::$app->response->sendContentAsFile(json_encode($this->catalog()->export(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),'craft-catalog-v1.json',['mimeType'=>'application/json']);
    }
    public function actionTemplate()
    {
        $data=require dirname(__DIR__).'/data/default-catalog.php';
        return Yii::$app->response->sendContentAsFile(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),'classic-craft-v1.json',['mimeType'=>'application/json']);
    }
    private function document(): array
    {
        $file=UploadedFile::getInstanceByName('catalog_file');
        if($file&&($file->error!==UPLOAD_ERR_OK||$file->size>2097152))throw new UnprocessableEntityHttpException('Файл должен быть не больше 2 МБ.');
        $raw=$file?file_get_contents($file->tempName):Yii::$app->request->post('document','');
        if(!is_string($raw)||strlen($raw)>2097152)throw new UnprocessableEntityHttpException('Документ должен быть не больше 2 МБ.');
        $data=json_decode($raw,true,64); if(!is_array($data)||json_last_error()!==JSON_ERROR_NONE)throw new UnprocessableEntityHttpException('Некорректный JSON.');
        return $data;
    }
    public function actionPreview()
    {
        try { $data=$this->document(); $preview=$this->catalog()->preview($data); return $this->render('preview',['document'=>json_encode($data,JSON_UNESCAPED_UNICODE),'preview'=>$preview]); }
        catch(\yii\web\HttpException $e) { Yii::$app->response->statusCode=$e->statusCode; return $this->render('index',['catalog'=>$this->catalog()->export(),'error'=>$e->getMessage(),'chargeCredits'=>$this->settings()->chargeCredits(),'inventorySettings'=>$this->settings()->inventory()]); }
    }
    public function actionImport()
    {
        $data=$this->document(); $digest=Yii::$app->request->post('digest');
        if(!is_string($digest))throw new UnprocessableEntityHttpException('Сначала выполните предварительную проверку.');
        $counts=$this->catalog()->apply($data,$digest);
        Yii::info(['actor'=>Yii::$app->user->id,'counts'=>$counts],'craft.catalog.import');
        Yii::$app->session->setFlash('success','Каталог сохранён. Игровые инвентари и история сохранены.'); return $this->redirect(['index']);
    }
    public function actionEdit(string $group,string $code='')
    {
        $fields=[
            'categories'=>['code'=>'','name'=>'','description'=>''],
            'items'=>['code'=>'','name'=>'','label'=>'','description'=>'','category'=>'','kind'=>'material','rarity'=>'common','icon'=>'cube','stack_size'=>100,'destroyable'=>1,'use_xp'=>0,'gather_quantity'=>0,'active'=>1,'storage_kind'=>'none'],
            'stations'=>['code'=>'','name'=>'','item'=>null,'active'=>1],
            'recipes'=>['code'=>'','name'=>'','description'=>'','category'=>'','output'=>'','output_quantity'=>1,'cost_credits'=>0,'experience'=>10,'min_level'=>1,'station'=>null,'active'=>1,'ingredients'=>[],'tools'=>[],'requires'=>[]],
        ];
        if(!isset($fields[$group]))throw new \yii\web\NotFoundHttpException();
        $all=$this->catalog()->export();$record=$fields[$group];$found=false;
        foreach($all[$group] as $r)if($r['code']===$code){$record=array_replace($record,$r);$found=true;}
        if($code&&!$found)throw new \yii\web\NotFoundHttpException();
        if(Yii::$app->request->isPost) {
            $post=Yii::$app->request->post('record',[]); if(!is_array($post))throw new UnprocessableEntityHttpException();
            foreach($fields[$group] as $key=>$default) {
                $value=$post[$key]??'';
                if(!is_string($value))throw new UnprocessableEntityHttpException('Неверное поле '.$key);
                if(is_int($default)) { if(!preg_match('/^\d+$/D',$value))throw new UnprocessableEntityHttpException('Нужно целое число: '.$key); $value=(int)$value; }
                elseif(is_array($default)) {
                    $parts=preg_split('/[\r\n,]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY);$value=[];
                    foreach($parts as $part) { if($key==='ingredients'){ $pair=explode(':',trim($part)); if(count($pair)!==2||!ctype_digit(trim($pair[1])))throw new UnprocessableEntityHttpException('Ингредиент: код:количество');$value[]=['item'=>trim($pair[0]),'quantity'=>(int)trim($pair[1])]; }else $value[]=trim($part); }
                } elseif($default===null&&$value==='')$value=null;
                $record[$key]=$value;
            }
            if($group==='items'&&trim($record['label'])==='')$record['label']=$record['name'];
            if($found)$record['code']=$code;
            $patch=['version'=>1,'categories'=>[],'items'=>[],'stations'=>[],'recipes'=>[]]; $patch[$group]=[$record];
            $preview=$this->catalog()->preview($patch);
            return $this->render('preview',['document'=>json_encode($patch,JSON_UNESCAPED_UNICODE),'preview'=>$preview]);
        }
        return $this->render('edit',['group'=>$group,'record'=>$record,'catalog'=>$all,'existing'=>$found]);
    }
}
