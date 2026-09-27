<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:18
 */

namespace api\modules\v1\models\history;

class HistoryBalance extends \common\models\history\HistoryBalance
{
    public function fields(): array
    {
        $parents = parent::fields();

        $fields = [
            'id',
            'user_id',
            'balance',
            'balance_up',
            'credit',
            'credit_up',
            'created_at',
        ];

        $fields['type'] = $parents['type'];
        $fields['comment'] = $parents['comment'];
        if (\common\modules\economy\service\WalletSchema::ready(static::getDb())) {
            foreach (['credit', 'credit_up'] as $column) {
                foreach ($fields as $key => $field) if (is_int($key) && $field === $column) unset($fields[$key]);
                $fields[$column] = static function (self $model) use ($column): float { return (float)$model->$column; };
                $fields[$column . '_exact'] = static function (self $model) use ($column): string { return \common\modules\economy\value\Money::parse((string)$model->$column)->decimal(); };
            }
        }

        return $fields;
    }
}
