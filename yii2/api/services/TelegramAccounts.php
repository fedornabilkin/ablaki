<?php
namespace api\services;

use api\models\TelegramUser;
use api\modules\v1\models\User;
use common\services\user\PresenceService;
use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\ConflictHttpException;

/** Adapter to the existing account/session lifecycle; no second token system. */
final class TelegramAccounts
{
    public function create(): int
    {
        if (!Yii::$app->getModule('user')->enableRegistration) {
            throw new ForbiddenHttpException('Регистрация временно недоступна.');
        }
        $user = new TelegramUser(['scenario' => 'telegram']);
        // Do not use a Telegram username for identity matching or expose the Telegram ID publicly.
        $user->username = 'player_' . bin2hex(random_bytes(6));
        $user->email = null;
        $user->password = Yii::$app->security->generateRandomString(48);
        if (!$user->register()) throw new \RuntimeException('Telegram account registration failed.');
        return (int)$user->id;
    }

    public function session(int $id, ?string $expectedHash = null): array
    {
        $user = User::findOne($id);
        if (!$user || $user->getIsBlocked()) throw new ForbiddenHttpException('Аккаунт недоступен.');
        if ($expectedHash !== null && !hash_equals($expectedHash, hash('sha256', (string)$user->auth_key))) {
            throw new ConflictHttpException('Вход уже завершён или отменён. Начните заново.');
        }
        if (!is_string($user->auth_key) || trim($user->auth_key) === '') {
            (new \api\models\LoginForm())->changeAuthKey($user);
        }
        if (!Yii::$app->user->login($user)) throw new ForbiddenHttpException('Вход недоступен.');
        $user->updateAttributes(['last_login_at' => time()]);
        PresenceService::recordActivity($id);
        return ['user' => $user, 'token' => $user->auth_key];
    }
}
