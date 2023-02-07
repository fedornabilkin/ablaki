<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 21:46
 */

namespace common\services\history;

use common\models\history\{HistoryBalance, HistoryRating};
use yii\db\ActiveQuery;

class HistoryService
{
    public const HT_EVERYDAY = 'everyday';

    /**
     * For backend admin panel
     * @return array
     */
    public function types(): array
    {
        $models = $this->groupBalanceTypes()->all();
        $types = [];
        foreach ($models as $model) {
            $types[$model->type] = $model->type;
        }
        return $types;
    }

    public function groupBalanceTypes(): ActiveQuery
    {
        return HistoryBalance::find()
            ->select(['type'])
            ->groupBy(['type']);
    }

    public function groupRatingTypes(): ActiveQuery
    {
        return HistoryRating::find()
            ->select(['type'])
            ->groupBy(['type']);
    }
}
