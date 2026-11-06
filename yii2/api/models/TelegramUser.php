<?php
namespace api\models;

/** Uses the same registration lifecycle and profile creation, without inventing an email. */
final class TelegramUser extends \api\modules\v1\models\User
{
    public function scenarios()
    {
        return array_merge(parent::scenarios(), ['telegram' => ['username', 'password']]);
    }
}
