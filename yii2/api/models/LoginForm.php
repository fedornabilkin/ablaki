<?php
namespace api\models;

use Exception;
use common\services\user\PresenceService;
use Yii;
use yii\base\Exception as BaseException;
use yii\web\IdentityInterface;

/**
 * Login form
 */
class LoginForm extends \dektrium\user\models\LoginForm
{
    public $token;

    public function getUser()
    {
        return $this->user;
    }

    public function loginKey(string $key): bool
    {
        $this->token = null;
        if ($key === '') {
            return false;
        }
        $module = Yii::$app->getModule('user');

        $userModel = $module->modelMap['User'];
//        var_dump($userModel);exit;
        $this->user = $userModel::find()->where(['auth_key' => $key])->one();

        if ($this->user && $this->user->auth_key !== '') {
            $isLogged = Yii::$app->getUser()->login($this->user);

            if ($isLogged) {
                $this->user->updateAttributes(['last_login_at' => time()]);
                $this->changeAuthKey($this->user);
                $this->token = $this->user->auth_key;
                PresenceService::recordActivity((int)$this->user->getId());
            }

            return $isLogged;
        }

        return false;
    }

    /**
     * @return bool whether the user is logged in successfully
     * @throws BaseException
     */
    public function login(): bool
    {
        $this->token = null;
        if (!parent::login()) {
            return false;
        }
        if (!is_string($this->user->auth_key) || trim($this->user->auth_key) === '') {
            $this->changeAuthKey($this->user);
        }
        $this->token = $this->user->auth_key;
        PresenceService::recordActivity((int)$this->user->getId());

        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function logout(): bool
    {
        $this->changeAuthKey(Yii::$app->user->identity);

        return Yii::$app->user->logout();
    }

    public function changeAuthKey(IdentityInterface $user): void
    {
        $token = Yii::$app->security->generateRandomString();
        if ($user->updateAttributes(['auth_key' => $token]) !== 1) {
            throw new BaseException('Unable to persist authentication token.');
        }
    }

    public function responseApi(): array
    {
        return [
            'user' => $this->getUser(),
            'token' => $this->token,
        ];
    }
}
