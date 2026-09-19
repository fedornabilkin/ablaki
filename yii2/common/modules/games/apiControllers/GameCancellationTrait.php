<?php

namespace common\modules\games\apiControllers;

use common\helpers\App;
use common\modules\games\service\GameCancellationService;

trait GameCancellationTrait
{
    protected function verbs()
    {
        return array_merge(parent::verbs(), ['remove' => ['DELETE']]);
    }

    public function actionDelete(int $id): void
    {
        (new GameCancellationService())->cancel(substr($this->modelClass::tableName(), 5), (int)App::user()->id, $id);
        App::response()->setStatusCode(204);
    }

    public function actionRemove(): array
    {
        return (new GameCancellationService())->cancel(substr($this->modelClass::tableName(), 5), (int)App::user()->id);
    }
}
