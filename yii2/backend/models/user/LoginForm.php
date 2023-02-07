<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.02.2023
 * Time: 23:41
 */

namespace backend\models\user;

use Yii;

class LoginForm extends \dektrium\user\models\LoginForm
{
    public function loginKey(string $key): bool
    {
        $module = Yii::$app->getModule('user');

        $userModel = $module->modelMap['User'];
        $this->user = $userModel::find()->where(['auth_key' => $key])->one();

        if ($this->user) {
            $isLogged = Yii::$app->getUser()->login($this->user);

            if ($isLogged) {
                $this->user->updateAttributes(['last_login_at' => time()]);
                $this->user->auth_key = Yii::$app->security->generateRandomString();
                $this->user->save();
            }

            return $isLogged;
        }

        return false;
    }
}
