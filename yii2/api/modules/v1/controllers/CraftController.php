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
        if (!Yii::$app->db->schema->getTableSchema('craft_command',true)||!Yii::$app->db->schema->getTableSchema('craft_tool_wear',true)) throw new \yii\web\HttpException(503,'Мастерская обновляется. Попробуйте немного позже.');
        return new Crafting(new CraftStorage(Yii::$app->db));
    }
    /** @deprecated Use the node-scoped world workspace and supplies routes. */
    private function deprecated(): void { Yii::$app->response->headers->set('Deprecation', 'true'); }
    public function actionIndex(): array { $this->deprecated(); return $this->engine()->state((int)Yii::$app->user->id); }
    public function actionCommand(): array
    {
        $this->deprecated();
        $body=Yii::$app->request->bodyParams;
        if (!is_array($body)||!is_string($body['action']??null)||!is_string($body['request_key']??null)) throw new \yii\web\UnprocessableEntityHttpException('Некорректная команда.');
        try { return $this->engine()->command((int)Yii::$app->user->id,$body['request_key'],$body['action'],$body); }
        catch (\common\services\game\GameError $error) { throw new \yii\web\HttpException($error->status, $error->getMessage()); }
    }
    public function actionHistory()
    {
        $this->deprecated();
        $this->engine();
        return \api\components\ApiList::provider((new \yii\db\Query())->from('craft_event')->where(['user_id'=>(int)Yii::$app->user->id]),[],['id','created_at']);
    }
}
