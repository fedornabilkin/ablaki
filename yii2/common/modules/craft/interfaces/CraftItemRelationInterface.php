<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 13:20
 */

namespace common\modules\craft\interfaces;

use common\modules\craft\models\CraftItem;
use yii\db\ActiveQuery;

/**
 * @property CraftItem $item
 */
interface CraftItemRelationInterface
{
    public function getItem(): ActiveQuery;
}
