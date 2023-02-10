<?php

namespace common\services;

use common\models\Commission;
use yii\db\ActiveQuery;

class CommissionService
{
    /**
     * For backend admin panel
     * @return array
     */
    public function types(): array
    {
        $models = $this->groupTypes()->all();
        $types = [];
        foreach ($models as $model) {
            $type = trim($model->type);
            $types[$type] = $type;
        }
        return $types;
    }

    public function groupTypes(): ActiveQuery
    {
        return Commission::find()
            ->select(['type'])
            ->groupBy(['type']);
    }
}
