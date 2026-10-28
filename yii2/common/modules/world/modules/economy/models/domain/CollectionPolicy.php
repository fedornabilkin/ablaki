<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\models\domain\WorldAccessPolicy;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\models\domain\WorldQuery;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Publishing changes future collections and future receipts; old invoices/receipts keep their revisions. */
class CollectionPolicy
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }

    public static function input(array $body, int $node): array
    {
        $input = ['node_id' => $node];
        foreach (['rate_bps' => [0, 10000], 'due_seconds' => [3600, 31536000], 'protected_seconds' => [0, 31536000], 'loss_period_seconds' => [3600, 2592000], 'loss_rate_bps' => [0, 10000]] as $key => $limits) {
            if (!is_int($body[$key] ?? null) || $body[$key] < $limits[0] || $body[$key] > $limits[1]) throw new GameError('INVALID_FINANCE_POLICY', 'Некорректное значение правила: ' . $key, 422);
            $input[$key] = $body[$key];
        }
        if (!is_string($body['reason'] ?? null) || trim($body['reason']) === '' || mb_strlen($body['reason'], 'UTF-8') > 255) throw new GameError('REASON_REQUIRED', 'Укажите причину изменения.', 422);
        $input['reason'] = trim($body['reason']);
        return $input;
    }
    private function prepare(array $input): array
    {
        $this->access->requireAdmin(); WalletSchema::requireReady($this->db);
        $node = (new WorldQuery($this->db, $this->access))->node($input['node_id']);
        if (!$node['has_finances']) throw new GameError('PARENT_BUDGET_REQUIRED', 'У этого объекта нет отдельного бюджета. Настройте финансы родителя.', 422);
        if ($node['status'] !== 'active') throw new GameError('FINANCE_UNAVAILABLE', 'Объект недоступен.');
        $current = (new EconomyHierarchy($this->db))->current($node['id']);
        $parent = $current ? $current['parent_node_id'] : $node['parent_id'];
        if ($parent === null && $input['rate_bps'] !== 0) throw new GameError('ROOT_RATE_MUST_BE_ZERO', 'У корня мира нет получателя отчислений.', 422);
        return ['revisions' => ['node:' . $node['id'] => $node['revision']], 'terms' => $input + [
            'parent_node_id' => $parent, 'revision' => ($current ? $current['revision'] : 1) + 1,
            'basis' => 'collected_revenue', 'existing_receipts_unchanged' => true, 'existing_obligations_unchanged' => true,
        ]];
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.economy.policy', $input, function () use ($input) { return $this->prepare($input); });
    }
    public function publish(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $this->access->requireAdmin();
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.economy.policy', $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $bus) {
            if (CanonicalJson::encode($this->prepare($payload)['terms']) !== CanonicalJson::encode($terms)) throw new GameError('FINANCE_POLICY_CHANGED', 'Правило изменилось. Повторите расчёт.');
            $accounts = (new EconomyHierarchy($this->db))->provision($payload['node_id'], $operation);
            $subject = (int)$accounts['budget']['subject_id'];
            $current = (new Query())->from('economy_parent_rule')->where(['subject_id' => $subject])->one($this->db);
            $previous = (new Query())->from('economy_parent_history')->where(['id' => $current['history_id']])->one($this->db);
            $now = time();
            $this->db->createCommand()->update('economy_parent_history', ['effective_to' => $now], ['id' => $previous['id'], 'effective_to' => null])->execute();
            $this->db->createCommand()->insert('economy_parent_history', [
                'subject_id' => $subject, 'parent_subject_id' => $previous['parent_subject_id'], 'revision' => $terms['revision'], 'status' => 'published',
                'basis' => 'collected_revenue', 'rate_bps' => $payload['rate_bps'], 'due_seconds' => $payload['due_seconds'],
                'effective_from' => $now, 'effective_to' => null, 'operation_id' => $operation, 'reason' => $payload['reason'],
            ])->execute();
            $history = (int)$this->db->getLastInsertID();
            $this->db->createCommand()->insert('economy_collection_policy', ['history_id' => $history, 'protected_seconds' => $payload['protected_seconds'], 'loss_period_seconds' => $payload['loss_period_seconds'], 'loss_rate_bps' => $payload['loss_rate_bps']])->execute();
            $this->db->createCommand()->insert('economy_tax_checkpoint', ['history_id' => $history, 'fraction' => 0])->execute();
            $this->db->createCommand()->update('economy_parent_rule', ['history_id' => $history, 'revision' => $terms['revision']], ['subject_id' => $subject])->execute();
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $payload['node_id']])->execute();
            $bus->emit($operation, $user, 'economy.policy_published', ['node_id' => $payload['node_id'], 'history_id' => $history, 'revision' => $terms['revision']]);
            return ['changed_node_ids' => [$payload['node_id']]];
        });
    }
}
