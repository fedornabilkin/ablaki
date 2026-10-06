<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;

/** AR protection for both Person model names; CreditLedger writes through locked SQL. */
trait PersonalCreditRecord
{
    public function beforeSave($insert)
    {
        WalletMaintenance::writable(static::getDb());
        if (!parent::beforeSave($insert)) return false;
        if (WalletSchema::ready(static::getDb())) {
            if ($insert) {
                $credit = Money::parse((string)($this->getAttribute('credit') ?? '0'));
                if (!$credit->isZero()) throw new \LogicException('New personal accounts start at zero; use CreditLedger for grants.');
                $this->setAttribute('credit', '0.0000');
            } elseif ($this->isAttributeChanged('credit')) throw new \LogicException('Use CreditLedger to change personal credit.');
        }
        return true;
    }
    public static function updateAll($attributes, $condition = '', $params = [])
    {
        WalletMaintenance::writable(static::getDb());
        if (array_key_exists('credit', $attributes) && WalletSchema::ready(static::getDb())) throw new \LogicException('Use CreditLedger to change personal credit.');
        return parent::updateAll($attributes, $condition, $params);
    }
    public static function updateAllCounters($counters, $condition = '', $params = [])
    {
        WalletMaintenance::writable(static::getDb());
        if (array_key_exists('credit', $counters) && WalletSchema::ready(static::getDb())) throw new \LogicException('Use CreditLedger to change personal credit.');
        return parent::updateAllCounters($counters, $condition, $params);
    }
    public static function deleteAll($condition = null, $params = [])
    {
        WalletMaintenance::writable(static::getDb());
        if (WalletSchema::ready(static::getDb())) throw new \LogicException('Use the locked account cleanup service.');
        return parent::deleteAll($condition, $params);
    }
}
