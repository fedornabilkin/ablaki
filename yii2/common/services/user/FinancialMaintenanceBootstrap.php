<?php
namespace common\services\user;

use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Module;

/** Applies equally to API, server pages, administration and scheduled console commands. */
final class FinancialMaintenanceBootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        $app->on(Module::EVENT_BEFORE_ACTION, static function (ActionEvent $event) use ($app): void {
            $web = $app instanceof \yii\web\Application;
            if ($web && FinancialMaintenance::readAllowed($event->action->getUniqueId(), $app->request->method)) return;
            // Set only by the explicit, path-checked deployment command, never by HTTP input.
            if (!$web && getenv('WORLD_PRODUCTION_UPGRADE') === 'confirmed-production-checkout') return;
            if (FinancialMaintenance::enterWriter()) return;
            if ($web) {
                $app->response->headers->set('Retry-After', '60');
                throw new \yii\web\ServiceUnavailableHttpException('Денежные операции временно недоступны: обновляем кошелёк. Попробуйте позже.');
            }
            throw new \RuntimeException('Financial maintenance: scheduled writers are temporarily paused.');
        });
    }
}
