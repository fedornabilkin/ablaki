<?php

namespace api\modules\v1\models;

use common\models\history\HistoryBalance;
use common\services\user\PublicProfile;

/** Public record of an actually credited daily reward. */
class DailyBonus extends HistoryBalance
{
    public function fields(): array
    {
        return [
            'id', 'created_at',
            'amount' => static function (self $record) { return (float)$record->credit_up; },
            'user' => static function (self $record) { return PublicProfile::fromUser($record->user); },
        ];
    }
}
