<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\CraftInventory;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Shared grant rule while the legacy craft endpoint is retired. */
class SupplyGrant
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function plan(int $user, string $action): array
    {
        if (!in_array($action, ['starter', 'gather'], true)) throw new GameError('INVALID_ACTION', 'Неизвестный вид снабжения.', 422);
        $period = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        $event = (new Query())->from('craft_event')->where(['user_id' => $user, 'action' => $action]);
        if ($action === 'gather') $event->andWhere(['>=', 'created_at', $period]);
        if ($event->exists($this->db)) throw new GameError('SUPPLIES_ALREADY_CLAIMED', $action === 'starter' ? 'Стартовый набор уже получен.' : 'Сырьё на сегодня уже собрано.');
        $efficiency = $action === 'gather' ? (new NightWorkEfficiency($this->db))->basisPoints($user) : 10000;
        $items = []; $sizes = [];
        foreach ((new Query())->from('craft_item')->where(['active' => 1])->andWhere(['>', 'gather_quantity', 0])->orderBy(['id' => SORT_ASC])->all($this->db) as $item) {
            $base = (int)$item['gather_quantity'] * ($action === 'starter' ? 3 : 1);
            $items[] = ['item_id' => (int)$item['id'], 'name' => (string)$item['name'], 'quantity' => max(1, intdiv($base * $efficiency, 10000))];
            $sizes[(int)$item['id']] = (int)$item['stack_size'];
        }
        if (!$items) throw new GameError('SUPPLIES_UNAVAILABLE', 'Источники сырья пока не настроены.');
        $inventory = new CraftInventory(new CraftStorage($this->db)); $active = $inventory->capacity($user)['active_slots'];
        $occupied = 0; $spare = [];
        foreach ($inventory->layout($user) as $row) {
            if ((int)$row['slot'] > $active || (int)$row['item_quantity'] < 1) continue;
            $occupied++;
            $id = (int)$row['item_id'];
            if (isset($sizes[$id])) $spare[$id] = ($spare[$id] ?? 0) + max(0, $sizes[$id] - (int)$row['item_quantity']);
        }
        foreach ($items as $entry) {
            $id = $entry['item_id']; $remaining = max(0, $entry['quantity'] - ($spare[$id] ?? 0));
            if ($sizes[$id] < 1 || $sizes[$id] > 10000) throw new GameError('SUPPLIES_UNAVAILABLE', 'Размер стопки материала некорректен.');
            $occupied += intdiv($remaining + $sizes[$id] - 1, $sizes[$id]);
        }
        if ($occupied > $active) throw new GameError('BACKPACK_FULL', 'Освободите активные ячейки рюкзака перед получением материалов.');
        return ['items' => $items, 'efficiency_bps' => $efficiency, 'period_started_at' => $action === 'gather' ? $period : null];
    }
    public function grant(CraftStorage $store, int $user, string $action, ?array $approved = null): string
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Supply grant requires a transaction.');
        $plan = $this->plan($user, $action);
        if ($approved !== null && ($plan['items'] !== $approved['items'] || $plan['efficiency_bps'] !== $approved['efficiency_bps']
            || $plan['period_started_at'] !== $approved['period_started_at'])) throw new GameError('SUPPLIES_CHANGED', 'Набор материалов изменился. Повторите расчёт.');
        $total = 0;
        foreach ($plan['items'] as $entry) {
            $item = (new Query())->from('craft_item')->where(['id' => $entry['item_id'], 'active' => 1])->one($this->db);
            if (!$item) throw new \RuntimeException('Supply item changed.');
            $store->move($user, $item, $entry['quantity']); $total += $entry['quantity'];
        }
        $this->db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => $action, 'quantity' => $total, 'credit_change' => 0, 'created_at' => time()])->execute();
        return $action === 'starter' ? 'Стартовые материалы получены.' : 'Сырьё на сегодня собрано.';
    }
}
