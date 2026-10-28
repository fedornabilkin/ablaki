<?php
namespace common\modules\world\modules\craft\apiControllers;
use api\modules\v1\traites\AuthTrait;
use common\modules\world\modules\craft\models\domain\Crafting;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use Yii;
use yii\rest\Controller;

class CraftController extends Controller
{
    use AuthTrait;
    public function authExceptAction(): array { return ['options']; }
    public function actionOptions() { Yii::$app->response->statusCode = 204; return null; }
    protected function verbs() { return ['index'=>['GET'],'command'=>['POST'],'history'=>['GET']]; }
    private function engine(): Crafting
    {
        \common\modules\world\modules\craft\models\CraftEvent::requireSchema();
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
        catch (\common\modules\world\support\GameError $error) { throw new \yii\web\HttpException($error->status, $error->getMessage()); }
    }
    public function actionHistory()
    {
        $this->deprecated();
        $this->engine();
        return \api\components\ApiList::provider(\common\modules\world\modules\craft\models\CraftEvent::history((int)Yii::$app->user->id),[],['id','created_at']);
    }
}
