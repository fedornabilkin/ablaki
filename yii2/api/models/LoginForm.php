<?php
namespace api\models;

use Exception;
use Yii;
use yii\base\Exception as BaseException;

/**
 * Login form
 */
class LoginForm extends \dektrium\user\models\LoginForm
{
    public $token;

    /**
     * @return bool whether the user is logged in successfully
     * @throws BaseException
     */
    public function login()
    {
        $parent = parent::login();
        $this->token = $this->user->auth_key;

        return $parent;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function logout()
    {
        $user = \Yii::$app->user->identity;
        $user->auth_key = \Yii::$app->security->generateRandomString();
        $user->save();

        return Yii::$app->user->logout();
    }
}
