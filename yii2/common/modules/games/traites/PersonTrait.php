<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 19:44
 */

namespace common\modules\games\traites;

use common\singletones\Person;

trait PersonTrait
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
