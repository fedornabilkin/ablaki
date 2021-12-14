<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 22:39
 */

namespace common\models\user;

use common\models\Todo;
use yii\db\ActiveQuery;

/**
 * @property Person $person
 * @property Todo $todo
 */
trait Relations
{
    /**
     * @return ActiveQuery
     */
    public function getPerson(): ActiveQuery
    {
        /** @var $this User */
        return $this->hasOne(Person::class, ['user_id' => 'id'])->inverseOf('user');
    }

    /**
     * @return ActiveQuery
     */
    public function getTodo(): ActiveQuery
    {
        /** @var $this User */
        return $this->hasMany(Todo::class, ['user_id' => 'id']);
    }
}
