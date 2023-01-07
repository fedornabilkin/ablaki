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

    public const SCENARIO_CREATE = 'create';

    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_CREATE] = ['count', 'countMin', 'type', 'credit', 'amount', 'created_at'];
        return $scenarios;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['countNumber'] = [['count'], 'number', 'on' => self::SCENARIO_CREATE];
        $rules['countMin'] = [['count'], 'number', 'min' => 1, 'on' => self::SCENARIO_CREATE];
        $rules[] = [['credit', 'amount'], 'required', 'on' => self::SCENARIO_CREATE];
        $rules[] = [['credit', 'amount'], 'number', 'on' => self::SCENARIO_CREATE];
        $rules[] = [['credit'], 'number', 'min' => 1, 'on' => self::SCENARIO_CREATE];
        $rules[] = [['amount'], 'number', 'min' => 0.01, 'on' => self::SCENARIO_CREATE];

        $rules['typeRequired'] = [['type'], 'required', 'on' => self::SCENARIO_CREATE];
        $rules['typeRange'] = ['type', 'in', 'range' => $this->getAvailableTypes(), 'on' => self::SCENARIO_CREATE];

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
