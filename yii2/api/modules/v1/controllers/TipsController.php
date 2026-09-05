<?php

namespace api\modules\v1\controllers;

use common\models\Fact;
use yii\rest\Controller;

class TipsController extends Controller
{
    public function actionRandom()
    {
        $query = Fact::find()->select(['id', 'title', 'type'])->where(['!=', 'hide', 1]);
        $count = (int)(clone $query)->count();
        if ($count === 0) {
            return null;
        }
        return $query->orderBy(['id' => SORT_ASC])->offset(random_int(0, $count - 1))->limit(1)->asArray()->one();
    }
}
