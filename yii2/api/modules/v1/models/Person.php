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
                $own = !App::user()->getIsGuest() && (int)App::user()->id === (int)$model->user_id;
                return $own || !$model->hasAttribute('description_approved') || (bool)$model->description_approved
                    ? $model->getAttribute('description') : null;
            },
            'description_approved' => static function (self $model) {
                return $model->hasAttribute('description_approved') && (bool)$model->description_approved;
            },
            'forum_credits_sent' => static function (self $model): int {
                return \common\modules\forum\services\CommentGiftSchema::sent(\Yii::$app->db, (int)$model->user_id);
            },
        ];

//        $f['id2'] = function (self $model) {
//            return $model->user_id .  ' ' . $this->user_id;
//        };

        // todo отображается для action profile, не отображается на стене. Непонятно как работает.
        if (!App::user()->getIsGuest() && App::user()->identity->getId() === $this->user_id) {
            $f[] = 'balance';
            $f['credit'] = static function (self $model) {
                return \common\modules\world\modules\economy\models\domain\WalletSchema::ready(static::getDb()) ? (float)$model->credit : $model->credit;
            };
            if (\common\modules\world\modules\economy\models\domain\WalletSchema::ready(static::getDb())) $f['credit_exact'] = static function (self $model): string {
                return \common\modules\world\modules\economy\value\Money::parse((string)$model->credit)->decimal();
            };
        }

        return $f;
    }
}
