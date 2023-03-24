<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 19:16
 */

namespace common\modules\craft\service;

use common\modules\craft\models\CraftCategory;
use common\modules\craft\models\CraftItem;
use yii\helpers\ArrayHelper;

class FilterService
{
    public function categoryFilter(string $key = 'name'): array
    {
        return ArrayHelper::map(CraftCategory::find()->asArray()->all(), $key, 'name');
    }

    public function itemFilter(string $key = 'name'): array
    {
        return ArrayHelper::map(CraftItem::find()->asArray()->all(), $key, 'name');
    }
}
