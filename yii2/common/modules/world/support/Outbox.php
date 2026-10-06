<?php
namespace common\modules\world\support;

use yii\db\Connection;
use yii\db\Query;

class Outbox
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    /** subscriptions: event type => consumer names; delivery is a durable job per consumer. */
    public function dispatch(array $subscriptions, int $limit = 100): int
    {
        if (!$subscriptions) return 0;
        return $this->db->transaction(function () use ($subscriptions, $limit) {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $events = (new Query())->from('game_outbox')->where(['dispatched_at' => null, 'event_type' => array_keys($subscriptions)])
                ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->limit(max(1, min(500, $limit)))->all($this->db);
            $queue = new JobQueue($this->db);
            foreach ($events as $event) {
                foreach ($subscriptions[$event['event_type']] as $consumer) {
                    if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $consumer)) throw new \LogicException('Invalid outbox consumer.');
                    $queue->enqueue('outbox.delivery', $event['id'] . ':' . $consumer, ['event_id' => $event['id'], 'consumer' => $consumer], $event['user_id'] === null ? null : (int)$event['user_id']);
                }
                $this->db->createCommand()->update('game_outbox', ['dispatched_at' => time()], ['id' => $event['id']])->execute();
            }
            return count($events);
        });
    }
    public function deliver(array $payload, array $consumers): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Delivery requires the job transaction.');
        $consumer = $payload['consumer']; $id = $payload['event_id'];
        if (!isset($consumers[$consumer]) || !is_callable($consumers[$consumer])) throw new GameError('CONSUMER_UNAVAILABLE', 'Обработчик события недоступен.', 503);
        if ((new Query())->from('game_inbox')->where(['consumer' => $consumer, 'event_id' => $id])->exists($this->db)) return;
        $event = (new Query())->from('game_outbox')->where(['id' => $id])->one($this->db);
        if (!$event) throw new \LogicException('Outbox event missing.');
        $consumers[$consumer](json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR), $event);
        $this->db->createCommand()->insert('game_inbox', ['consumer' => $consumer, 'event_id' => $id, 'processed_at' => time()])->execute();
    }
}
