<?php
namespace common\modules\world\modules\economy\models\domain;

use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Module;

/** Stops new requests/console actions during offline wallet preparation. */
class WalletMaintenanceBootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        $app->on(Module::EVENT_BEFORE_ACTION, static function (ActionEvent $event) use ($app) {
            if (!WalletMaintenance::frozen($app->db)) return;
            $route = $event->action->getUniqueId();
            if ($app instanceof \yii\console\Application && in_array($route, [
                'world-setup/wallet-status', 'world-setup/wallet-freeze', 'world-setup/wallet-snapshot',
                'world-setup/wallet-convert', 'world-setup/wallet-verify', 'world-setup/wallet-cancel', 'world-audit/credits',
                'world-setup/wallet-activate', 'world-setup/test-ready',
            ], true)) return;
            if ($app instanceof \yii\web\Application) throw new \yii\web\ServiceUnavailableHttpException('Кошелёк временно недоступен: перенос кредитов.');
            throw new \RuntimeException('Wallet maintenance: only wallet rollout/status/audit commands are available.');
        });
    }
}
