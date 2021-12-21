<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.12.2021
 * Time: 17:30
 */

namespace common\helpers;

use Yii;
use yii\base\InvalidConfigException;
use yii\web\User;

/**
 * @method static User user()
 */
class App
{
    public static function __callStatic($name, $args)
    {
        return static::getComponent($name);
    }

    /**
     * @param $componentName
     *
     * @return object|null
     *
     * @throws InvalidConfigException
     */
    public static function getComponent($componentName)
    {
        return Yii::$app->get($componentName);
    }
}
