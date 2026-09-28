<?php
namespace common\modules\world\service;

use common\modules\craft\service\CraftStorage;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Manual starter grant and daily gathering at the active campsite. */
class WorldSupplies
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function prepare(int $user, array $input): array
    {
        $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2');
        if (!in_array($input['action'], ['starter', 'gather'], true)) throw new GameError('INVALID_ACTION', 'Неизвестный вид снабжения.', 422);
        $site = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($input['node_id']);
        $details = (array)$site['details'];
        if ($site['type'] !== 'PLOT' || $site['status'] !== 'active' || !$site['permissions']['storage'] || ($details['plot_kind'] ?? '') !== 'campsite'
            || !(new Query())->from('world_membership')->where(['user_id' => $user, 'starter_site_id' => $site['id'], 'world_id' => $site['root_id']])->exists($this->db)) {
            throw new GameError('CAMPSITE_OWNER_REQUIRED', 'Получайте материалы на собственной стоянке.', 403);
        }
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')
            ->where(['c.descendant_id' => $site['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('CAMPSITE_UNAVAILABLE', 'Стоянка временно недоступна.');
        $plan = (new SupplyGrant($this->db))->plan($user, $input['action']);
        $revisions = ['node:' . $site['id'] => $site['revision'], 'catalog' => (int)(new Query())->select('revision')->from('craft_meta')->where(['id' => 1])->scalar($this->db)];
        $backpack = (new \common\modules\craft\service\CanonicalInventory(new CraftStorage($this->db)))->backpack($user);
        if ($backpack) $revisions['storage:' . $backpack['id']] = (int)$backpack['revision'];
        return ['terms' => ['node_id' => $site['id'], 'action' => $input['action']] + $plan, 'revisions' => $revisions];
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.supplies.' . $input['action'], $input,
            function () use ($user, $input) { return $this->prepare($user, $input); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.supplies.' . $input['action'], $input, $quote, $revisions,
            function (array $payload, array $terms, string $operation) use ($user, $bus) {
                $prepared = $this->prepare($user, $payload);
                if (CanonicalJson::encode($prepared['terms']) !== CanonicalJson::encode($terms)) throw new GameError('SUPPLIES_CHANGED', 'Набор материалов изменился. Повторите расчёт.');
                $store = new CraftStorage($this->db); $store->operationId = $operation;
                $message = (new SupplyGrant($this->db))->grant($store, $user, $payload['action'], $terms);
                $backpack = (new \common\modules\craft\service\CanonicalInventory($store))->backpack($user);
                $result = ['message' => $message, 'changed_node_ids' => [$payload['node_id']], 'changed_storage_ids' => $backpack ? [(int)$backpack['id']] : []];
                $bus->emit($operation, $user, 'world.supplies.' . $payload['action'], $result);
                return $result;
            });
    }
}
