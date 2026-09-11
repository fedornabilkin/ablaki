<?php

namespace api\components;

use dektrium\user\Mailer;
use dektrium\user\models\Token;
use dektrium\user\models\User;

/** API registration must not fail when SMTP is not configured. */
class SilentMailer extends Mailer
{
    public function sendWelcomeMessage(User $user, Token $token = null, $showPassword = false)
    {
        return true;
    }

    public function sendConfirmationMessage(User $user, Token $token)
    {
        return true;
    }

    public function sendGeneratedPassword(User $user, $password)
    {
        return true;
    }
}
