<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 21:18
 */

namespace common\modules\exchange\api\models;

use common\modules\exchange\service\ExchangeService;

class CreditExchange extends \common\modules\exchange\models\CreditExchange
{
    public $count = 1;

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['countNumber'] = [['count'], 'number'];
        $rules['countMin'] = [['count'], 'number', 'min' => 1];

        return $rules;
    }

    public function fields(): array
    {
        $fields = parent::fields();

        $fields['type'] = static function (self $model) {
            return trim($model->type);
        };

        $fields['user_client'] = static function (self $model) {
            return $model->user_buyer;
        };

        $fields['price'] = static function (self $model) {
            return round((new ExchangeService())->pricePerThousand($model), 2);
        };

        unset($fields['user_buyer']);

        return $fields;
    }
}
