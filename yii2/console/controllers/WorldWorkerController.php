<?php
namespace console\controllers;

use common\services\game\JobQueue;
use common\services\game\Outbox;
use Yii;

class WorldWorkerController extends \yii\console\Controller
{
    public function actionRun(int $limit = 100): int
    {
        if (getenv('WORLD_WORKER') !== '1') throw new \RuntimeException('World worker is disabled.');
        $module = Yii::$app->getModule('world');
        $subscriptions = $module->params['subscriptions'] ?? [];
        $consumers = $module->params['consumers'] ?? [];
        $handlers = $module->params['jobHandlers'] ?? [];
        $flags = new \common\modules\world\service\WorldFlags(Yii::$app->db, $module);
        if ($flags->capabilities()['world_write'] && \common\modules\economy\service\WalletSchema::ready(Yii::$app->db)) {
            $handlers['economy.treasury.loss'] = function (array $payload) use ($flags) {
                $flags->requireFlag('world_write');
                (new \common\modules\economy\service\TreasuryLedger(Yii::$app->db))->runLoss($payload);
            };
            $handlers['economy.order.expire'] = function (array $payload) use ($flags) {
                $flags->requireFlag('world_write');
                (new \common\modules\economy\service\StarterOrders(Yii::$app->db, $flags, new \common\modules\world\service\WorldAccessPolicy(0)))->expire($payload);
            };
        }
        $outbox = new Outbox(Yii::$app->db); $queue = new JobQueue(Yii::$app->db);
        $dispatched = $outbox->dispatch($subscriptions);
        if ($consumers) $handlers['outbox.delivery'] = function (array $payload) use ($outbox, $consumers) { $outbox->deliver($payload, $consumers); };
        $done = 0; $failed = 0;
        for ($i = 0; $i < max(1, min(1000, $limit)); $i++) {
            $job = $queue->claim(array_keys($handlers));
            if (!$job) break;
            if ($queue->perform($job, $handlers[$job['type']])) $done++; else $failed++;
        }
        $this->stdout(json_encode(['dispatched' => $dispatched, 'completed' => $done, 'failed' => $failed], JSON_THROW_ON_ERROR) . "\n");
        return $failed ? 1 : 0;
    }
}
