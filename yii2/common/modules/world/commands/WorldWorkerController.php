<?php
namespace common\modules\world\commands;

use common\modules\world\support\JobQueue;
use common\modules\world\support\Outbox;
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
        $flags = new \common\modules\world\models\domain\WorldFlags(Yii::$app->db, $module);
        if ($flags->capabilities()['world_read']) {
            $notifications = new \common\modules\world\models\domain\WorldNotifications(Yii::$app->db, $flags);
            foreach (array_keys(\common\modules\world\models\domain\WorldNotifications::EVENTS) as $type) $subscriptions[$type] = array_values(array_unique(array_merge($subscriptions[$type] ?? [], ['world.notifications.v1'])));
            $consumers['world.notifications.v1'] = function (array $payload, array $event) use ($notifications) { $notifications->consume($payload, $event); };
        }
        if ($flags->capabilities()['world_write'] && $flags->capabilities()['storage_v2']) {
            $construction = new \common\modules\world\models\domain\WorldConstruction(Yii::$app->db, $flags);
            $handlers['world.construction.finish'] = function (array $payload, array $job) use ($construction) { $construction->finish($payload, $job); };
            $wear = new \common\modules\world\models\domain\EquipmentWearJob(Yii::$app->db, $flags);
            $wear->discover();
            $handlers['world.equipment.wear'] = function (array $payload, array $job) use ($wear) { $wear->settle($payload, $job); };
            $nights = new \common\modules\world\models\domain\WorldNights(Yii::$app->db, $flags);
            $handlers['world.night.enroll'] = function (array $payload) use ($nights) { $nights->enroll($payload); };
            $handlers['world.night.resolve'] = function (array $payload, array $job) use ($nights) { $nights->resolve($payload, $job); };
        }
        if ($flags->capabilities()['world_write'] && \common\modules\world\modules\economy\models\domain\WalletSchema::ready(Yii::$app->db)) {
            $handlers['economy.treasury.loss'] = function (array $payload) use ($flags) {
                $flags->requireFlag('world_write');
                (new \common\modules\world\modules\economy\models\domain\TreasuryLedger(Yii::$app->db))->runLoss($payload);
            };
            $handlers['economy.order.expire'] = function (array $payload) use ($flags) {
                $flags->requireFlag('world_write');
                (new \common\modules\world\modules\economy\models\domain\StarterOrders(Yii::$app->db, $flags, new \common\modules\world\models\domain\WorldAccessPolicy(0)))->expire($payload);
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
