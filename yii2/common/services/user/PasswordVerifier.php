<?php

namespace common\services\user;

use dektrium\user\helpers\Password;
use dektrium\user\models\User;
use yii\base\InvalidArgumentException;

/** Read-only password verification shared by login and sensitive account changes. */
final class PasswordVerifier
{
    public function verify(User $user, string $password): bool
    {
        if ($password === '') return false;
        if ((new LegacyPasswordService())->verify($user, $password)) return true;
        try {
            return is_string($user->password_hash) && $user->password_hash !== ''
                && Password::validate($password, $user->password_hash);
        } catch (InvalidArgumentException $error) {
            return false;
        }
    }
}
