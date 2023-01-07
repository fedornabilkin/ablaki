<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 0:47
 */

namespace common\singletones;


use Yii;

/**
 * @deprecated
 */
class Person
{
    private static $instance;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance() {
        if (self::$instance == NULL) {
            self::$instance = self::getAuthPerson();
        }
        return self::$instance;
    }

    private static function getAuthPerson()
    {
        $id = (!Yii::$app->user->isGuest) ? Yii::$app->user->identity->id : null;
        return $id > 0 ? Yii::$app->user->identity->person : $id;
    }
}
