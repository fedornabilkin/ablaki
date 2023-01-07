<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 17:09
 */

namespace api\modules\v1\models;

use common\helpers\App;
use yii\db\ActiveQuery;

class User extends \common\models\user\User
{
    /**
     * @return ActiveQuery
     */
    public function getPerson(): ActiveQuery
    {
        return $this->hasOne(Person::class, ['user_id' => 'id'])->inverseOf('user');
    }

    public function fields(): array
    {
        $f = [
            'id',
            'created_at',
            'last_login_at',
            'username',
            'person',
        ];

        // todo отображается для action profile, не отображается на стене. Непонятно как работает.
        if (!App::user()->getIsGuest() && App::user()->identity->getId() === $this->id) {
            $f[] = 'email';
        }

        return $f;
    }
}
