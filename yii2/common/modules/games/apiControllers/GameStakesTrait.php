<?php

namespace common\modules\games\apiControllers;

use common\helpers\App;
use Yii;
use yii\web\BadRequestHttpException;

trait GameStakesTrait
{
    private function lobbyQuery(string $scope)
    {
        $query = $this->modelClass::find();
        if ($scope === 'my') $query->listMyGame(App::user()->identity);
        elseif ($scope === 'available') $query->listGame(App::user()->identity);
        else throw new BadRequestHttpException('Invalid game scope.');
        return $query;
    }

    public function actionStakes(): array
    {
        $scope = Yii::$app->request->get('scope', 'available');
        if (!is_string($scope)) throw new BadRequestHttpException('Invalid game scope.');
        return $this->lobbyQuery($scope)->select(['kon', 'COUNT(*) AS count'])
            ->limit(null)->offset(null)->groupBy(['kon'])->orderBy(['kon' => SORT_ASC])->asArray()->all();
    }

    private function applyStakeFilter($query): void
    {
        $filter = Yii::$app->request->get('filter', []);
        if (!is_array($filter)) throw new BadRequestHttpException('Invalid game filter.');
        if (!isset($filter['kon']) || $filter['kon'] === '') return;
        $stake = $filter['kon'];
        if (!is_scalar($stake) || !is_numeric($stake) || !is_finite((float)$stake) || (float)$stake <= 0) {
            throw new BadRequestHttpException('Invalid stake.');
        }
        $query->andWhere(['kon' => $stake]);
    }
}
