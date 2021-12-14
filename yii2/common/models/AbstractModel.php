<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 30.06.2018
 * Time: 16:42
 */

namespace common\models;


use common\singletones\Person;
use yii\db\ActiveRecord;

class AbstractModel extends ActiveRecord
{
    /**
     * Возвращает модель персоны текущего пользователя
     *
     * @return null|ActiveRecord
     * @deprecated
     */
    public function getPersonInstance()
    {
        return Person::getInstance();
    }
}
