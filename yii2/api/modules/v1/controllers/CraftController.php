<?php
namespace api\modules\v1\controllers;
use api\modules\v1\traites\AuthTrait;
use common\modules\craft\service\Crafting;
use common\modules\craft\service\CraftStorage;
use Yii;
use yii\rest\Controller;

class CraftController extends Controller
{
    use AuthTrait;
    public function authExceptAction(): array { return ['options']; }
    protected function verbs() { return ['index'=>['GET'],'command'=>['POST'],'history'=>['GET']]; }
    private function engine(): Crafting
    {
        if (!Yii::$app->db->schema->getTableSchema('craft_command',true)) throw new \yii\web\ServiceUnavailableHttpException('Крафт готовится к запуску.');
        return new Crafting(new CraftStorage(Yii::$app->db));
    }
    public function actionIndex(): array { return $this->engine()->state((int)Yii::$app->user->id); }
    public function actionCommand(): array
    {
        $body=Yii::$app->request->bodyParams;
        if (!is_array($body)||!is_string($body['action']??null)||!is_string($body['request_key']??null)) throw new \yii\web\UnprocessableEntityHttpException('Некорректная команда.');
        return $this->engine()->command((int)Yii::$app->user->id,$body['request_key'],$body['action'],$body);
    }
    public function actionHistory()
    {
        $this->engine();
        return \api\components\ApiList::provider((new \yii\db\Query())->from('craft_event')->where(['user_id'=>(int)Yii::$app->user->id]),[],['id','created_at']);
    }
}
