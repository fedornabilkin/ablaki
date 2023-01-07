<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.12.2019
 * Time: 21:08
 */

namespace common\helpers;

use yii\helpers\Inflector;

/**
 * Class Env
 * @package common\helpers
 *
 * @method static string redisHost()
 * @method static string redisPort()
 * @method static string redisPassword()
 * @method static string redisCacheDatabase()
 */
class Env
{
    public static function __callStatic($name, $arguments)
    {
        return getenv('BLK_' . strtoupper(Inflector::underscore($name)));
    }
}
