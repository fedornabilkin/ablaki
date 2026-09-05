<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 12:17
 */

namespace common\modules\exchange\api\models;

class CreditTransfer extends \common\modules\exchange\models\CreditTransfer
{
    public $count = 1;

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['countMin'] = [['count'], 'number', 'min' => 1];

        return $rules;
    }

    public function fields(): array
    {
        return [
            'id',
            'user_id',
            'user_buyer',
            'amount',
            'username' => static function (self $model) {
                return $model->user === null ? null : $model->user->username;
            },
            'username_buyer' => static function (self $model) {
                return $model->userBuyer === null ? null : $model->userBuyer->username;
            },
            'password' => static function (self $model) {
                return (int)$model->user_id === (int)\Yii::$app->user->id ? trim((string)$model->password) : null;
            },
            'created_at',
            'updated_at',
        ];
    }
}
