<?php
namespace common\models\user;

use common\services\user\PasswordVerifier;
use Yii;

/** Shared password validation; confirmation, blocking and login lifecycle stay with Dektrium. */
class PasswordLoginForm extends \dektrium\user\models\LoginForm
{
    public function rules()
    {
        $rules = parent::rules();
        if (isset($rules['passwordValidate'])) {
            $rules['passwordValidate'] = ['password', function ($attribute) {
                if ($this->user && is_string($this->password)
                    && (new PasswordVerifier())->verify($this->user, $this->password)) return;
                $this->addError($attribute, Yii::t('user', 'Invalid login or password'));
            }];
        }
        return $rules;
    }
}
