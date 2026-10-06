<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\Command;
use common\modules\world\support\GameError;
use Yii;

abstract class CoreController extends LegacyController
{
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) return false;
        if ($action->id !== 'options') $this->flags()->requireFlag('world_read');
        return true;
    }
    protected function verbs()
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'create' => ['POST'], 'start' => ['POST'],
            'heartbeat' => ['POST'], 'stop' => ['POST'], 'cancel' => ['POST'], 'options' => ['OPTIONS']];
    }
    protected function command(string $type, array $payload, callable $handler): array
    {
        $key = Yii::$app->request->headers->get('Idempotency-Key', Yii::$app->request->post('request_key'));
        if (!is_string($key)) throw new GameError('INVALID_REQUEST_KEY', 'Передайте Idempotency-Key.', 422);
        return Command::run((int)Yii::$app->user->id, $key, $type, $payload, $handler);
    }
    protected function listing(\yii\data\ActiveDataProvider $provider): array
    {
        return ['items' => array_map(static function ($model) { return $model->toArray(); }, $provider->getModels()),
            '_meta' => ['totalCount' => $provider->getTotalCount(), 'pageCount' => $provider->pagination->pageCount,
                'currentPage' => $provider->pagination->page + 1, 'perPage' => $provider->pagination->pageSize]];
    }
}
