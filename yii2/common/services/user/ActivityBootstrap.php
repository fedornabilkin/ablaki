<?php
namespace common\services\user;

use yii\base\BootstrapInterface;
use yii\base\Module;
use yii\web\Application;
use yii\web\User;

/** Includes public pages opened with a valid bearer token, as well as session pages. */
class ActivityBootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        if (!$app instanceof Application) return;
        $app->on(Module::EVENT_BEFORE_ACTION, static function () use ($app) {
            if ($app->request->isOptions) return;
            if (!$app->user->isGuest) {
                PresenceService::recordActivity((int)$app->user->id);
            } elseif (preg_match('/^Bearer\s+(.+)$/i', (string)$app->request->headers->get('Authorization'), $matches)) {
                // Do not log in, change keys, or reject a public page for an invalid token.
                $class = $app->user->identityClass;
                try {
                    $identity = $class::findIdentityByAccessToken($matches[1], \api\filters\Auth::class);
                    if ($identity) PresenceService::recordActivity((int)$identity->getId());
                } catch (\Throwable $error) {
                    \Yii::warning('Unable to identify optional activity (' . get_class($error) . ').', __METHOD__);
                }
            }
        });
        $app->user->on(User::EVENT_AFTER_LOGIN, static function ($event) {
            PresenceService::recordActivity((int)$event->identity->getId());
        });
    }
}
