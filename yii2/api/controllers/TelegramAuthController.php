<?php
namespace api\controllers;

use api\modules\v1\models\User;
use api\services\TelegramAccounts;
use api\services\TelegramAuthService;
use common\services\auth\AuthRateLimit;
use common\services\auth\TelegramConfig;
use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;

final class TelegramAuthController extends Controller
{
    protected function verbs()
    {
        return ['config' => ['GET'], 'identity' => ['GET'], 'start' => ['POST'], 'status' => ['POST'],
            'complete' => ['POST'], 'cancel' => ['POST'], 'unlink' => ['POST'], 'bot-prepare' => ['POST'], 'bot-confirm' => ['POST'], 'bot-register' => ['POST']];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->headers->set('Cache-Control', 'no-store, private');
        Yii::$app->response->headers->set('Pragma', 'no-cache');
        Yii::$app->response->headers->set('Referrer-Policy', 'no-referrer');
        if (!parent::beforeAction($action)) return false;
        if ($action->id !== 'config') {
            if (str_starts_with($action->id, 'bot-')) $this->authenticateBot();
            if (!in_array($action->id, ['bot-register', 'identity', 'unlink'], true) && !$this->config()->available()) throw new HttpException(503, 'Вход через Telegram пока недоступен.');
            (new AuthRateLimit(Yii::$app->db))->hit('telegram.' . $action->id,
                (string)Yii::$app->request->userIP, in_array($action->id, ['start', 'unlink'], true) ? 10 : 120);
        }
        return true;
    }

    public function actionConfig(): array
    {
        return ['telegram' => ['enabled' => $this->config()->available()]];
    }

    public function actionBotRegister(): array
    {
        $id = Yii::$app->request->post('bot_id');
        $username = Yii::$app->request->post('bot_username');
        if (!is_string($id) || !is_string($username)) throw new BadRequestHttpException('Некорректные данные бота.');
        (new \common\services\auth\TelegramSettings(Yii::$app->db))->registerBot($id, $username);
        return ['bot' => ['registered' => true], 'site' => ['name' => 'Аблакин',
            'host' => YII_ENV_DEV ? 'localhost:5173' : 'ablakin.ru']];
    }

    public function actionIdentity(): array
    {
        $user = $this->actor(true);
        return ['telegram' => $this->service()->identity((int)$user->id)];
    }

    public function actionStart(): array
    {
        $purpose = Yii::$app->request->post('purpose', 'login');
        if (!in_array($purpose, ['login', 'link'], true)) throw new BadRequestHttpException('Неверный способ входа.');
        $allowSignup = Yii::$app->request->post('allow_signup', false);
        if (!is_bool($allowSignup)) throw new BadRequestHttpException('allow_signup должен быть булевым.');
        $user = $this->actor($purpose === 'link');
        return $this->service()->start($purpose, $allowSignup, $user ? (int)$user->id : null, $user?->auth_key);
    }

    public function actionUnlink(): array
    {
        $user = $this->actor(true);
        $password = Yii::$app->request->post('password');
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            throw new BadRequestHttpException('Введите пароль аккаунта.');
        }
        return $this->service()->unlink((int)$user->id, $user->auth_key, $password);
    }

    public function actionStatus(): array { return $this->browserCommand('status'); }
    public function actionComplete(): array { return $this->browserCommand('complete'); }
    public function actionCancel(): array { return $this->browserCommand('cancel'); }

    public function actionBotPrepare(): array
    {
        $username = Yii::$app->request->post('telegram_username');
        if ($username !== null && (!is_string($username) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $username))) {
            throw new BadRequestHttpException('Неверное имя пользователя Telegram.');
        }
        return $this->service()->prepare($this->token('request_id'), $this->subject(), $username);
    }

    public function actionBotConfirm(): array
    {
        $approve = Yii::$app->request->post('approve');
        if (!is_bool($approve)) throw new BadRequestHttpException('approve должен быть булевым.');
        return $this->service()->decide($this->token('request_id'), $this->subject(), $approve);
    }

    private function browserCommand(string $method): array
    {
        $user = $this->actor(false);
        return $this->service()->$method($this->token('request_id'), $this->token('browser_secret'),
            $user ? (int)$user->id : null, $user?->auth_key);
    }

    private function actor(bool $required): ?User
    {
        $authorization = Yii::$app->request->headers->get('Authorization', '');
        $user = preg_match('/^Bearer (\S+)$/D', $authorization, $matches) ? User::findIdentityByAccessToken($matches[1]) : null;
        if ($user && $user->getIsBlocked()) throw new ForbiddenHttpException('Аккаунт недоступен.');
        if (!$user && ($required || $authorization !== '')) throw new UnauthorizedHttpException('Войдите в аккаунт.');
        // Cookie identities must never authorize binding; only an explicit browser bearer token does.
        Yii::$app->user->setIdentity($user);
        return $user;
    }

    private function authenticateBot(): void
    {
        $config = $this->config();
        $header = Yii::$app->request->headers->get('Authorization', '');
        if (strlen($config->apiKey) < 32 || !hash_equals('Bearer ' . $config->apiKey, $header)) throw new UnauthorizedHttpException('Invalid bot credentials.');
        if (!YII_ENV_DEV && !Yii::$app->request->isSecureConnection) throw new ForbiddenHttpException('HTTPS is required.');
        if ($config->botIps && !in_array(Yii::$app->request->userIP, $config->botIps, true)) {
            throw new ForbiddenHttpException('Bot address is not allowed.');
        }
    }

    private function token(string $field): string
    {
        $value = Yii::$app->request->post($field);
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $value)) throw new BadRequestHttpException('Некорректная заявка.');
        return $value;
    }

    private function subject(): string
    {
        $value = Yii::$app->request->post('telegram_user_id');
        // String IDs avoid precision loss between PHP, JSON and the future bot runtime.
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,15}$/D', $value)) throw new BadRequestHttpException('Некорректный Telegram ID.');
        return $value;
    }

    private function config(): TelegramConfig { return TelegramConfig::fromApplication(); }
    private function service(): TelegramAuthService { return new TelegramAuthService(Yii::$app->db, $this->config(), new TelegramAccounts()); }
}
