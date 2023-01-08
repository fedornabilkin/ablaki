<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 12:40
 */

namespace common\models\core;

use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;

trait ModelQueryTrait
{
    /**
     * @return ActiveQuery
     * @throws InvalidConfigException
     */
    public static function find(): ActiveQuery
    {
        $className = static::class . 'Query';
        if (!class_exists($className)) {
            $className = ActiveQuery::class;
        }

        return new $className(static::class);
    }
}
