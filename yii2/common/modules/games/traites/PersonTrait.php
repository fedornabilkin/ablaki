<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 19:44
 */

namespace common\modules\games\traites;

use common\singletones\Person;
use yii\db\ActiveRecord;

/**
 * @deprecated
 */
trait PersonTrait
{
    /**
     * Возвращает модель персоны текущего пользователя
     *
     * @return null|ActiveRecord
     */
    public function getPersonInstance()
    {
        return Person::getInstance();
    }
}
