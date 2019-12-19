<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 30.06.2018
 * Time: 16:42
 */

namespace common\models;


use common\singletones\Person;

class AbstractModel extends \yii\db\ActiveRecord
{
    /**
     * Возвращает модель персоны текущего пользователя
     *
     * @return null|\yii\db\ActiveRecord
     */
    public function getPersonInstance()
    {
        return Person::getInstance();
    }
}
