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
use yii\di\Container;
use yii\web\Request;
use yii\web\Response;
use yii\web\UrlManager;
use yii\web\User;

/**
 * @method static User user()
 * @method static Response response()
 * @method static UrlManager urlManager()
 * @method static Request|\yii\console\Request request()
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

    /**
     * @return Container
     */
    public static function container(): Container
    {
        return Yii::$container;
    }
}
