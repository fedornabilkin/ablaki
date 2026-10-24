<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage};
use common\modules\world\modules\economy\models\domain\{BudgetDisbursement, BudgetSpending, EconomyHierarchy};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\Query;

/** Paid mine batches: employer stock, accepted reserved wages, worker tools and health. */
final class HiredExtraction
{
    public static function configuration(Node $node): array
    {
        $settings = json_decode($node->settings_json ?: '{}', true) ?: [];
        $rule = $settings['hired_extraction'] ?? [];
        $local = self::storages($node);
        return ['enabled' => ($rule['enabled'] ?? false) === true, 'reward_cr' => (string)($rule['reward_cr'] ?? '1.0000'), 'storage_id' => $local ? (int)$local[0]['id'] : null];
    }
    public static function storages(Node $node, bool $accepted = false): array
    {
        return ExtractionStorage::local($node, $accepted);
    }
    public static function configure(Node $node, int $user, bool $enabled, string $reward, ?int $storage = null): array
    {
        $node->requireOwner($user);
        if (!$node->template || $node->template->code !== 'mine' || $node->status !== 'active') throw new GameError('HIRED_MINE_REQUIRED', 'Оплачиваемая добыча доступна в действующей шахте.', 422);
        try { $amount = Money::parse($reward); }
        catch (\InvalidArgumentException | \OverflowException $error) { throw new GameError('INVALID_WAGE', 'Укажите положительную оплату за партию, до четырёх знаков после точки.', 422); }
        if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_WAGE', 'Укажите положительную оплату за партию.', 422);
        if ($enabled && !self::storages($node)) throw new GameError('EMPLOYER_STORAGE_REQUIRED', 'Постройте действующий мини-склад внутри шахты.', 422);
        $settings = json_decode($node->settings_json ?: '{}', true) ?: [];
        // storage_id from older clients is ignored: the destination is selected by the server.
        $settings['hired_extraction'] = ['enabled' => $enabled, 'reward_cr' => $amount->decimal()];
        $node->settings_json = json_encode($settings, JSON_THROW_ON_ERROR);
        if (!$node->save(false)) throw new \RuntimeException('Mine employment settings write failed.');
        return ['changed_node_ids' => [(int)$node->id], 'employment' => self::configuration($node)];
    }
    public static function context(Node $node, int $user, bool $accepted = false): array
    {
        $db = Node::getDb(); $rule = self::configuration($node);
        if (!$node->template || $node->template->code !== 'mine' || (!$accepted && !$rule['enabled'])) throw new GameError('HIRED_WORK_CLOSED', 'Владелец шахты пока не разрешил оплачиваемую работу.', 403);
        if ((int)$node->max_condition < 1 || (int)$node->condition * 2 < (int)$node->max_condition) throw new GameError('MINE_REPAIR_REQUIRED', 'Для работы прочность шахты должна быть не ниже 50%.');
        if (!$accepted && WorldFood::state($db, $user)['hp'] < 60) throw new GameError('WORKER_HEALTH_REQUIRED', 'Для начала оплачиваемой работы нужно не менее 60% здоровья.');
        $storages = self::storages($node, $accepted); $target = null;
        foreach ($storages as $row) if ($accepted || $rule['storage_id'] === null || (int)$row['id'] === $rule['storage_id']) { $target = $row; break; }
        if (!$target) throw new GameError('EMPLOYER_STORAGE_REQUIRED', 'Постройте действующий мини-склад внутри шахты.');
        $accounts = (new EconomyHierarchy($db))->accounts((int)$node->id);
        if (!isset($accounts['budget'])) throw new GameError('BUDGET_UNAVAILABLE', 'Бюджет шахты ещё не подготовлен.');
        if (!$accepted && (new BudgetSpending($db))->available((int)$accounts['budget']['id'])->compare(Money::parse($rule['reward_cr'])) < 0) throw new GameError('INSUFFICIENT_WAGE_BUDGET', 'В бюджете шахты нет свободных средств на следующую партию.');
        return ['storage' => $target, 'budget_id' => (int)$accounts['budget']['id'], 'reward_cr' => $rule['reward_cr']];
    }
    public static function capacity(array $definition, array $target): void
    {
        $db = Node::getDb(); $inventory = new CanonicalInventory(new CraftStorage($db));
        foreach ($definition['output'] as $code => $quantity) {
            $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($db);
            if (!$item || !$inventory->planCraft([], $target, [], [], $item, $quantity)['output_fits']) throw new GameError('EMPLOYER_STORAGE_FULL', 'Склад шахты заполнен. Владельцу нужно освободить место.');
        }
    }
    public static function accept(Node $node, int $user, array $definition, string $operation): array
    {
        if ((new Query())->from('world_source_work')->where(['user_id' => $user])->andWhere(['not', ['active_key' => null]])->andWhere(['like', 'definition_json', '"employment"'])->exists(Node::getDb()))
            throw new GameError('HIRED_BATCH_UNFINISHED', 'Сначала завершите принятую оплачиваемую партию в другой шахте.', 409);
        $p = self::context($node, $user); self::capacity($definition, $p['storage']);
        $hold = (new BudgetSpending(Node::getDb()))->reserve($p['budget_id'], Money::parse($p['reward_cr']), 'Оплата партии шахты #' . $node->id, $operation);
        return $definition + ['employment' => ['owner_user_id' => (int)$node->owner_user_id, 'storage_id' => (int)$p['storage']['id'], 'budget_id' => $p['budget_id'], 'reward_cr' => $p['reward_cr'], 'commitment_id' => $hold]];
    }
    public static function target(Node $node, int $user, array $definition): array
    {
        self::context($node, $user, true);
        foreach (self::storages($node, true) as $row) if ((int)$row['id'] === (int)$definition['employment']['storage_id']) return $row;
        throw new GameError('EMPLOYER_STORAGE_REQUIRED', 'Принятый склад партии временно недоступен.');
    }
    public static function pay(int $user, int $work, array $definition, string $operation): void
    {
        if (!isset($definition['employment'])) return;
        $p = $definition['employment'];
        (new BudgetDisbursement(Node::getDb()))->wage((int)$p['budget_id'], $user, Money::parse($p['reward_cr']), $operation, 'mine-wage-' . $work, 'Оплата партии шахты', (int)$p['commitment_id']);
    }
    public static function state(Node $node, int $user, ?array $work): array
    {
        $own = (int)$node->owner_user_id === $user; $rule = self::configuration($node); $reason = null;
        if (!$own) try { self::context($node, $user, $work !== null); } catch (GameError $error) { $reason = $error->getMessage(); }
        return $rule + ['owner' => $own, 'available' => $own || $reason === null, 'reason' => $reason,
            'accepted_reward_cr' => $work ? (json_decode($work['definition_json'], true)['employment']['reward_cr'] ?? null) : null,
            'storages' => $own ? array_map(static function ($row) { return ['id' => (int)$row['id'], 'name' => 'Склад #' . $row['node_id'], 'capacity' => (int)$row['capacity']]; }, self::storages($node)) : []];
    }
}
