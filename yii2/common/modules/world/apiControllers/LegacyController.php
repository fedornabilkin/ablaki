<?php
namespace common\modules\world\apiControllers;

use api\modules\v1\traites\AuthTrait;
use common\modules\world\models\domain\WorldAccessPolicy;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\models\domain\WorldQuery;
use common\modules\world\support\GameError;
use Yii;

abstract class LegacyController extends \yii\rest\Controller
{
    use AuthTrait;
    public function authExceptAction(): array { return ['options']; }
    public function actionOptions() { Yii::$app->response->statusCode = 204; return null; }
    protected function verbs() { return ['index' => ['GET'], 'node' => ['GET'], 'children' => ['GET'], 'navigation' => ['GET'], 'map' => ['GET'], 'statistics' => ['GET'], 'actions' => ['GET'], 'campsite' => ['GET'], 'onboarding' => ['GET'], 'join-preview' => ['POST'], 'join' => ['POST'], 'move-preview' => ['POST'], 'move' => ['POST'], 'archive-preview' => ['POST'], 'archive' => ['POST'], 'storages' => ['GET'], 'storage' => ['GET'], 'storage-transfer-preview' => ['POST'], 'storage-transfer' => ['POST']]; }
    public function runAction($id, $params = [])
    {
        try { return parent::runAction($id, $params); }
        catch (GameError $error) {
            Yii::$app->response->statusCode = $error->status;
            return ['code' => $error->reason, 'message' => $error->getMessage(), 'details' => (object)$error->details];
        }
        catch (\yii\web\HttpException $error) {
            if ($error->statusCode >= 500) throw $error;
            Yii::$app->response->statusCode = $error->statusCode;
            return ['code' => 'WORLD_REQUEST_REJECTED', 'message' => $error->getMessage(), 'details' => (object)[]];
        }
    }
    protected function flags(): WorldFlags { return new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); }
    protected function policy(): WorldAccessPolicy
    {
        return new WorldAccessPolicy((int)Yii::$app->user->id, Yii::$app->user->can('world-manage'));
    }
    protected function reader(): WorldQuery { $this->flags()->requireFlag('world_read'); return new WorldQuery(Yii::$app->db, $this->policy()); }
    protected function id($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value) || (float)$value > 2147483647) throw new GameError('INVALID_ID', 'Некорректный идентификатор.', 422);
        return (int)$value;
    }
    protected function orderSearch(): string
    {
        $search = Yii::$app->request->get('q', '');
        if (!is_string($search) || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_ORDER_FILTER', 'Некорректная строка поиска.', 422);
        return trim($search);
    }
}
