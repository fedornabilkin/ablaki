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
        $finite = $action === 'gather' ? (new FiniteSupplies($this->db))->state($user) : null;
        $period = (new \DateTimeImmutable('@' . time()))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->getTimestamp();
        $event = (new Query())->from('craft_event')->where(['user_id' => $user, 'action' => $action]);
        if ($action === 'gather') $event->andWhere(['>=', 'created_at', $period]);
        if (!$finite && $event->exists($this->db)) throw new GameError('SUPPLIES_ALREADY_CLAIMED', $action === 'starter' ? 'Стартовый набор уже получен.' : 'Сырьё на сегодня уже собрано.');
        if ($finite && !$finite['gather_available']) throw new GameError($finite['gather_exhausted'] ? 'SUPPLIES_EXHAUSTED' : 'SUPPLIES_ALREADY_CLAIMED', $finite['gather_exhausted'] ? 'Снабжение исчерпано. Используйте построенную добычу; новые выдачи появятся только для новых предметов каталога.' : 'Сырьё на сегодня уже собрано.');
        $efficiency = $action === 'gather' ? (new NightWorkEfficiency($this->db))->basisPoints($user) : 10000;
        $items = []; $sizes = [];
        $revision = EconomyRuntime::current($this->db);
        $quantities = $revision ? $revision['profile'][$action === 'starter' ? 'starter' : 'daily']['items'] : null;
        if ($finite) { $quantities = []; foreach ($finite['items'] as $item) $quantities[$item['code']] = $item['quantity']; }
        $query = (new Query())->from('craft_item')->where(['active' => 1]);
        $quantities !== null ? $query->andWhere(['code' => array_keys($quantities)]) : $query->andWhere(['>', 'gather_quantity', 0]);
        foreach ($query->orderBy(['id' => SORT_ASC])->all($this->db) as $item) {
            $base = $quantities !== null ? $quantities[$item['code']] : (int)$item['gather_quantity'] * ($action === 'starter' ? 3 : 1);
            $entry = ['item_id' => (int)$item['id'], 'name' => (string)$item['name'], 'quantity' => max(1, intdiv($base * $efficiency, 10000))];
            if ($finite) foreach ($finite['items'] as $planned) if ($planned['item_id'] === $entry['item_id']) {
                $entry += ['code' => $planned['code'], 'ordinal' => $planned['ordinal'], 'base_quantity' => $planned['base_quantity'], 'grant_limit' => $planned['grant_limit'], 'initial_multiplier' => $planned['initial_multiplier']];
                $entry['quantity'] = max(1, intdiv($planned['base_quantity'] * $efficiency, 10000)) * ($planned['initial_multiplier'] - $planned['ordinal'] + 1);
                if ($planned['previous_quantity'] !== null) $entry['quantity'] = min($entry['quantity'], $planned['previous_quantity'] - 1);
            }
            $items[] = $entry;
            $sizes[(int)$item['id']] = (int)$item['stack_size'];
        }
        if ($quantities !== null && count($items) !== count($quantities)) throw new GameError('SUPPLIES_UNAVAILABLE', 'Стартовый каталог ещё не подготовлен.', 503);
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
        return ['items' => $items, 'efficiency_bps' => $efficiency, 'period_started_at' => $finite ? $finite['period_started_at'] : ($action === 'gather' ? $period : null)];
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
        $eventId = (int)$this->db->getLastInsertID();
        if ($action === 'gather' && isset($plan['items'][0]['ordinal'])) (new FiniteSupplies($this->db))->record($user, $eventId, $plan['items']);
        if ($store->operationId) EconomyRuntime::fact($this->db, $user, $action === 'starter' ? 'starter_claimed' : 'daily_supply_claimed', 1, $store->operationId);
        return $action === 'starter' ? 'Стартовые материалы получены.' : 'Сырьё на сегодня собрано.';
    }
}
