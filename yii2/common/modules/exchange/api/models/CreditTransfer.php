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
            'password' => static function (self $model) {
                return trim($model->password);
            },
            'created_at',
            'updated_at',
        ];
    }
}
