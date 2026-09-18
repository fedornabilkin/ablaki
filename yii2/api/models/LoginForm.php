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
    private $legacyPasswordAccepted = false;

    public function rules()
    {
        $rules = parent::rules();
        if (isset($rules['passwordValidate'])) {
            $rules['passwordValidate'] = ['password', function ($attribute) {
                $this->legacyPasswordAccepted = false;
                if ($this->user && is_string($this->password)) {
                    $this->legacyPasswordAccepted = (new \common\services\user\LegacyPasswordService())->verify($this->user, $this->password);
                    if ($this->legacyPasswordAccepted || $this->validCurrentPassword()) return;
                }
                $this->addError($attribute, Yii::t('user', 'Invalid login or password'));
            }];
        }
        return $rules;
    }

    public function afterValidate()
    {
        parent::afterValidate();
        if (!$this->legacyPasswordAccepted || $this->hasErrors()) return;
        $hash = \dektrium\user\helpers\Password::hash($this->password);
        $changed = $this->user::updateAll(['password_hash' => $hash], [
            'id' => $this->user->id,
            'password_hash' => $this->user->password_hash,
            'salt' => $this->user->getAttribute('salt'),
        ]);
        if ($changed !== 1) {
            $this->user->refresh();
            if (!$this->validCurrentPassword()) {
                $this->addError('password', Yii::t('user', 'Invalid login or password'));
            }
            return;
        }
        $this->user->password_hash = $hash;
    }

    private function validCurrentPassword(): bool
    {
        try {
            return is_string($this->user->password_hash) && $this->user->password_hash !== ''
                && \dektrium\user\helpers\Password::validate($this->password, $this->user->password_hash);
        } catch (\yii\base\InvalidArgumentException $error) { return false; }
    }

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
