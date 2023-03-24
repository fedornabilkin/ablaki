<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 00:16
 */

namespace common\models\core;

use yii\db\ActiveQuery;

/**
 * @mixin ActiveQuery
 */
trait ActiveQueryTrait
{
    public function active(): self
    {
        return $this->andWhere('[[active]]=1');
    }
}
