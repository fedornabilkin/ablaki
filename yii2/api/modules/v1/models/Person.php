<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 17:23
 */

namespace api\modules\v1\models;

use common\helpers\App;
use common\helpers\UserHelper;
use yii\db\Query;

class Person extends \common\models\user\Person
{

    public function fields()
    {

        $f = [
            'id',
            'bonus_count',
            'refovod',
            'rating' => static function (self $model) {
                return UserHelper::ratingRound($model->rating);
            },
            'description' => static function (self $model) {
                // Older profile schemas may not have this optional column yet.
                return $model->getAttribute('description');
            },
            'forum_credits_sent' => static function (self $model): int {
                return (int)(new Query())
                    ->from('{{%forum_comment_gift}}')
                    ->where(['user_id' => $model->user_id])
                    ->count();
            },
        ];

//        $f['id2'] = function (self $model) {
//            return $model->user_id .  ' ' . $this->user_id;
//        };

        // todo отображается для action profile, не отображается на стене. Непонятно как работает.
        if (!App::user()->getIsGuest() && App::user()->identity->getId() === $this->user_id) {
            $f[] = 'balance';
            $f[] = 'credit';
        }

        return $f;
    }
}
