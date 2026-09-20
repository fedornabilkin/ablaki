<?php
namespace common\modules\craft\controllers;
use common\modules\craft\service\CraftCatalog;
use common\modules\craft\service\CraftStorage;
use Yii;
use yii\web\UploadedFile;
use yii\web\UnprocessableEntityHttpException;

class CatalogController extends AdminController
{
    public function behaviors() { return ['verbs'=>['class'=>\yii\filters\VerbFilter::class,'actions'=>['preview'=>['POST'],'import'=>['POST'],'edit'=>['GET','POST'],'export'=>['GET']]]]; }
    private function catalog(): CraftCatalog { return new CraftCatalog(new CraftStorage(Yii::$app->db)); }
    public function actionIndex() { return $this->render('index',['catalog'=>$this->catalog()->export(),'error'=>'']); }
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
        catch(\yii\web\HttpException $e) { Yii::$app->response->statusCode=$e->statusCode; return $this->render('index',['catalog'=>$this->catalog()->export(),'error'=>$e->getMessage()]); }
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
            'items'=>['code'=>'','name'=>'','description'=>'','category'=>'','kind'=>'material','rarity'=>'common','icon'=>'cube','stack_size'=>100,'destroyable'=>1,'use_xp'=>0,'gather_quantity'=>0,'active'=>1],
            'stations'=>['code'=>'','name'=>'','item'=>null,'active'=>1],
            'recipes'=>['code'=>'','name'=>'','description'=>'','category'=>'','output'=>'','output_quantity'=>1,'cost_credits'=>0,'experience'=>10,'min_level'=>1,'station'=>null,'active'=>1,'ingredients'=>[],'tools'=>[],'requires'=>[]],
        ];
        if(!isset($fields[$group]))throw new \yii\web\NotFoundHttpException();
        $all=$this->catalog()->export();$record=$fields[$group];$found=false;
        foreach($all[$group] as $r)if($r['code']===$code){$record=$r;$found=true;}
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
            if($found)$record['code']=$code;
            $patch=['version'=>1,'categories'=>[],'items'=>[],'stations'=>[],'recipes'=>[]]; $patch[$group]=[$record];
            $preview=$this->catalog()->preview($patch);
            return $this->render('preview',['document'=>json_encode($patch,JSON_UNESCAPED_UNICODE),'preview'=>$preview]);
        }
        return $this->render('edit',['group'=>$group,'record'=>$record,'catalog'=>$all,'existing'=>$found]);
    }
}
