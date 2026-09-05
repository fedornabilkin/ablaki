<?php

namespace api\modules\v1\controllers;

use api\components\ApiList;
use api\modules\v1\models\history\HistoryBalance;
use api\modules\v1\models\history\HistoryRating;
use api\modules\v1\traites\AuthTrait;
use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;

class HistoryController extends Controller
{
    use AuthTrait;

    public function actionIndex()
    {
        // Retain the existing daily rewards feed's deliberately limited public fields.
        $query = HistoryBalance::find()->select(['type', 'credit_up', 'created_at'])
            ->where(['type' => 'everyday'])->asArray();
        return ApiList::provider($query, ['type']);
    }

    public function actionBalance()
    {
        return $this->history(HistoryBalance::class);
    }

    public function actionRating()
    {
        return $this->history(HistoryRating::class);
    }

    private function history(string $modelClass)
    {
        $query = $modelClass::find()->where(['user_id' => (int)Yii::$app->user->id]);
        $filter = Yii::$app->request->get('filter', []);
        if (!is_array($filter) || (isset($filter['type']) && !is_string($filter['type']))) {
            throw new BadRequestHttpException('Invalid history type filter.');
        }
        if (isset($filter['type']) && $filter['type'] !== '') {
            $query->andWhere(['type' => $filter['type']]);
        }
        return ApiList::provider($query, ['type', 'comment']);
    }

    public function actionBalanceType(): array
    {
        return $this->types(HistoryBalance::class);
    }

    public function actionRatingType(): array
    {
        return $this->types(HistoryRating::class);
    }

    private function types(string $modelClass): array
    {
        $rows = $modelClass::find()->select(['type', 'count' => 'COUNT(*)'])
            ->where(['user_id' => (int)Yii::$app->user->id])
            ->groupBy('type')->orderBy(['type' => SORT_ASC])->asArray()->all();
        return array_map(static function (array $row): array {
            return ['type' => trim((string)$row['type']), 'count' => (int)$row['count']];
        }, $rows);
    }
}
