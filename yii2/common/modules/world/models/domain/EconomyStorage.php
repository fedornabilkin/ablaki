<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\StorageAccessPolicy;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use common\services\user\CreditLedger;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Permanent additions preserve existing capacity, contents and temporary backpack leases. */
final class EconomyStorage
{
    private $db; private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function state(int $user, int $id): array
    {
        $this->flags->requireFlags(['world_read', 'storage_v2']);
        $storage = (new StorageAccessPolicy($this->db))->storage($user, $id, true); $profile = EconomyRuntime::current($this->db);
        $capacity = $storage['kind'] === 'backpack' ? (new CraftInventory(new CraftStorage($this->db)))->capacity($user)['permanent_slots'] : (int)$storage['capacity'];
        $node = $storage['node_id'] === null ? null : (new Query())->select('t.code')->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')->where(['n.id' => $storage['node_id']])->scalar($this->db);
        $options = [];
        foreach ($profile['profile']['storage']['expansions'] ?? [] as $code => $pack) if ($pack['kind'] === $storage['kind'] && ($pack['node_code'] === null || $pack['node_code'] === $node)) {
            $maximum = $storage['kind'] === 'backpack' ? min(CraftInventory::LIMIT, $pack['maximum']) : $pack['maximum'];
            if ($capacity + $pack['slots'] <= $maximum) $options[] = ['code' => $code, 'slots' => $pack['slots'], 'price_cr' => $pack['price_cr'], 'capacity_after' => $capacity + $pack['slots']];
        }
        return ['storage_id' => $id, 'capacity' => $capacity, 'options' => $options, 'profile_version' => $profile ? $profile['version'] : null,
            'writable' => $this->flags->capabilities()['world_write'], 'server_time' => time()];
    }
    private function prepare(int $user, int $id, string $code): array
    {
        $state = $this->state($user, $id); $selected = null;
        foreach ($state['options'] as $option) if ($option['code'] === $code) $selected = $option;
        if (!$selected) throw new GameError('STORAGE_EXPANSION_UNAVAILABLE', 'Этот пакет расширения сейчас недоступен.', 409);
        $storage = (new Query())->from('craft_storage')->where(['id' => $id])->one($this->db);
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
        return ['storage' => $storage, 'terms' => $selected + ['storage_id' => $id, 'capacity_before' => $state['capacity'], 'profile_version' => $state['profile_version'], 'payer' => 'personal_wallet'],
            'revisions' => ['storage:' . $id => (int)$storage['revision'], 'registry' => (int)$registry['content_revision']]];
    }
    public function preview(int $user, int $id, string $code): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.storage.expand', ['storage_id' => $id, 'code' => $code], function () use ($user, $id, $code) { return $this->prepare($user, $id, $code); });
    }
    public function execute(int $user, int $id, string $code, string $key, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.storage.expand', ['storage_id' => $id, 'code' => $code], $quote, $revisions, function ($input, $terms, $operation) use ($user, $id, $code, $bus) {
            $prepared = $this->prepare($user, $id, $code);
            if (CanonicalJson::encode($prepared['terms']) !== CanonicalJson::encode($terms)) throw new GameError('STORAGE_EXPANSION_CHANGED', 'Вместимость или цена изменилась. Повторите расчёт.', 409);
            (new CreditLedger($this->db))->changeExact($user, '-' . $terms['price_cr'], 'world_storage', 'Расширение хранилища #' . $id);
            $storage = $prepared['storage']; $next = $terms['capacity_after'];
            if ($storage['kind'] === 'backpack') $this->db->createCommand()->update('craft_capacity', ['permanent_slots' => $next], ['user_id' => $user])->execute();
            if ($storage['kind'] === 'chest') $this->db->createCommand()->update('craft_container', ['capacity' => $next], ['id' => $storage['container_inventory_id'], 'user_id' => $user])->execute();
            $values = ['revision' => new Expression('revision+1')]; if ($storage['kind'] !== 'backpack') $values['capacity'] = $next;
            if ($this->db->createCommand()->update('craft_storage', $values, ['id' => $id, 'revision' => $storage['revision']])->execute() !== 1) throw new \RuntimeException('Storage changed.');
            $this->db->createCommand()->insert('world_storage_expansion', ['user_id' => $user, 'storage_id' => $id, 'code' => $code, 'capacity_before' => $terms['capacity_before'],
                'capacity_after' => $next, 'price_cr' => $terms['price_cr'], 'profile_id' => EconomyRuntime::current($this->db)['id'], 'operation_id' => $operation, 'created_at' => time()])->execute();
            $result = ['changed_storage_ids' => [$id], 'changed_node_ids' => $storage['node_id'] === null ? [] : [(int)$storage['node_id']], 'capacity_after' => $next, 'paid_cr' => $terms['price_cr']];
            $bus->emit($operation, $user, 'world.storage.expanded', $result); return $result;
        });
    }
}
