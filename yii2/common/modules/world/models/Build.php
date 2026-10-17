<?php
namespace common\modules\world\models;

use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\helpers\Json;

class Build extends Record
{
    public static function tableName() { return 'world_build'; }
    public function getNode() { return $this->hasOne(Node::class, ['id' => 'node_id']); }
    public function getWork() { return $this->hasMany(BuildWork::class, ['build_id' => 'id']); }
    public function presentation(int $user): array
    {
        Node::readable((int)$this->node_id, $user);
        return ['build' => $this->toArray(), 'work' => $this->getWork()->asArray()->all()];
    }
    public static function begin(int $user, array $input, string $operation, bool $onboarding = false): self
    {
        self::requireTransaction();
        $parent = Node::readable($input['parent_id'], $user);
        if (!$onboarding || $parent->owner_user_id !== null) $parent->requireOwner($user);
        $template = NodeTemplate::requireOne($input['template_id']);
        if (!$template->player_buildable || !$template->enabled || !$template->permits($parent)) throw new GameError('INVALID_TEMPLATE', 'Этот объект здесь строить нельзя.', 422);
        $economyTerms = domain\EconomyConstruction::check($template, $user, $input, $onboarding);
        $cell = isset($input['x'], $input['y']) ? ['x' => $input['x'], 'y' => $input['y']]
            : ($onboarding ? null : (new \common\modules\world\models\domain\WorldTree(self::getDb()))->nextPosition((int)$parent->id));
        if ($cell) {
            (new \common\modules\world\models\domain\WorldTree(self::getDb()))->assertFreePosition((int)$parent->id, $cell['x'], $cell['y']);
            if (!(new \yii\db\Query())->from('world_map_cell')->where(['parent_id' => $parent->id, 'x' => $cell['x'], 'y' => $cell['y'], 'state' => 'open'])->exists())
                throw new GameError('MAP_CELL_CLOSED', 'Сначала исследуйте и откройте выбранную ячейку.', 422);
        }
        $budget = Money::parse($input['labor_budget']);
        if ($budget->isNegative()) throw new GameError('INVALID_BUDGET', 'Бюджет не может быть отрицательным.', 422);
        $hold = null;
        BuildRequirements::check($user, (int)$parent->id, $template);
        BuildMaterials::consume($user, $template->materials(), $operation, (int)$parent->id);
        if ($economyTerms) domain\EconomyPayments::charge(self::getDb(), $user, (int)$parent->id, Money::parse($economyTerms['price_cr']), (bool)($input['top_up'] ?? false), $operation, 'Постройка: ' . $template->name);
        $node = NodeFactory::create($template, $parent, $user, $input['name'], 'build-' . $operation, $cell, 'constructing');
        $accounts = (new EconomyHierarchy(self::getDb()))->provision((int)$node->id, $operation);
        if (!$budget->isZero()) {
            $source = $parent->owner_user_id == $user ? $parent : $node;
            $accounts = (new EconomyHierarchy(self::getDb()))->provision((int)$source->id, $operation);
            self::fund($user, $source, (int)$accounts['budget']['id'], $budget, (bool)($input['top_up'] ?? false), $operation);
            $hold = (new BudgetSpending(self::getDb()))->reserve((int)$accounts['budget']['id'], $budget, 'Строительство: ' . $template->name, $operation);
        }
        $build = new self(); $build->setAttributes(['node_id' => $node->id, 'parent_id' => $parent->id, 'owner_user_id' => $user,
            'required_seconds' => $template->build_seconds, 'worked_seconds' => 0, 'labor_budget' => $budget->decimal(), 'commitment_id' => $hold,
            'materials_json' => Json::encode((object)$template->materials()), 'status' => 'building', 'revision' => 1, 'created_at' => time()], false);
        if ($build->hasAttribute('active_node_id')) $build->active_node_id = $node->id;
        if (!$build->save(false)) throw new \RuntimeException('Build write failed.');
        return $build;
    }
    public function isRepair(): bool { return $this->getAttribute('job_kind') === 'repair'; }
    public static function repair(int $user, int $nodeId, string $operation): self
    {
        self::requireTransaction();
        if (!self::getDb()->schema->getTableSchema('world_build')->getColumn('job_kind')) throw new GameError('SCHEMA_NOT_READY', 'Ремонт пока не установлен.', 503);
        $node = Node::readable($nodeId, $user); $node->requireOwner($user);
        if ($node->status !== 'active') throw new GameError('REPAIR_UNAVAILABLE', 'Ремонт доступен только завершённому объекту.', 422);
        if (self::find()->where(['active_node_id'=>$nodeId])->exists()) throw new GameError('REPAIR_ALREADY_STARTED', 'Ремонт уже запущен.', 409);
        if ((new \yii\db\Query())->from(['n'=>'world_node'])->innerJoin(['c'=>'world_node_closure'], 'c.ancestor_id=n.id')->where(['c.descendant_id'=>$nodeId])->andWhere(['<>','n.status','active'])->exists()) throw new GameError('REPAIR_UNAVAILABLE', 'Родительский объект недоступен.', 409);
        $instance = BuildStrength::shelterInstance($nodeId);
        if ($instance) {
            (new \common\modules\world\modules\craft\models\domain\ProductionReservations(self::getDb()))->assertEquipment([$instance]);
            $unit = (new \common\modules\world\modules\craft\models\domain\EquipmentExposure(self::getDb()))->settle($instance);
            $node->condition = (int)$unit['durability']; $node->max_condition = (int)$unit['max_durability'];
            if (!$node->save(false)) throw new \RuntimeException('Shelter condition write failed.');
        }
        $missing = (int)$node->max_condition - (int)$node->condition;
        if ($missing <= 0) throw new GameError('REPAIR_NOT_NEEDED', 'Объект полностью исправен.', 422);
        $build = new self(); $build->setAttributes(['node_id'=>$nodeId,'active_node_id'=>$nodeId,'parent_id'=>$node->parent_id ?: $nodeId,'owner_user_id'=>$user,
            'job_kind'=>'repair','initial_condition'=>(int)$node->condition,'required_seconds'=>max(1,(int)ceil($missing * 60 / 100)),
            'worked_seconds'=>0,'labor_budget'=>'0.0000','materials_json'=>'{}','status'=>'building','revision'=>1,'created_at'=>time()], false);
        if (!$build->save(false)) throw new \RuntimeException('Repair write failed.');
        return $build;
    }
    public function assertOpen(): void
    {
        if ($this->status !== 'building') throw new GameError('BUILD_CLOSED', 'Стройка уже завершена или отменена.', 409);
        if ($this->isRepair()) {
            if (Node::requireOne($this->node_id)->status !== 'active') throw new GameError('REPAIR_UNAVAILABLE', 'Объект недоступен для ремонта.', 409);
            return;
        }
        $parent = Node::requireOne($this->parent_id);
        if ($parent->status !== 'active' || (int)$this->node->parent_id !== (int)$parent->id) throw new GameError('BUILD_UNAVAILABLE', 'Площадка недоступна.', 409);
    }
    public function configure(int $user, string $name, string $budgetValue, string $operation, bool $topUp = false): array
    {
        self::requireTransaction(); $this->assertOpen(); $node = $this->node; $node->requireOwner($user);
        $form = new BuildSettingsForm(); $form->setAttributes(['name'=>$name,'labor_budget'=>$budgetValue,'top_up'=>$topUp]);
        $validated = $form->input(); $name = $validated['name'];
        $budget = Money::parse($budgetValue); $old = Money::parse((string)$this->labor_budget);
        if ($budget->isNegative()) throw new GameError('INVALID_BUDGET', 'Бюджет не может быть отрицательным.', 422);
        if ($budget->compare($old) !== 0) {
            if ($budget->compare($old) < 0) throw new GameError('BUILD_BUDGET_DECREASE', 'Бюджет строительства можно только пополнять.', 422);
            $spending = new BudgetSpending(self::getDb());
            // Preserve the accepted funding account; a new commitment uses the owned parent budget when possible.
            $source = $this->fundingNode();
            $account = $this->commitment_id ? $spending->commitment((int)$this->commitment_id)['account_id'] : (new EconomyHierarchy(self::getDb()))->provision((int)$source->id, $operation)['budget']['id'];
            $fundingNode = (int)(new \yii\db\Query())->select('s.node_id')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[a.subject_id]]')->where(['a.id' => $account])->scalar(self::getDb());
            Node::requireOne($fundingNode)->requireOwner($user);
            self::fund($user, Node::requireOne($fundingNode), (int)$account, $budget->subtract($old), $topUp, $operation);
            if ($this->commitment_id) $spending->release((int)$this->commitment_id);
            $this->commitment_id = null;
            if (!$budget->isZero()) {
                $this->commitment_id = $spending->reserve((int)$account, $budget, 'Строительство: ' . $name, $operation);
            }
            $this->labor_budget = $budget->decimal();
        }
        $node->name = $node->label = $name;
        if ($this->isRepair()) { if (!$node->save(false)) throw new \RuntimeException('Repair settings write failed.'); }
        else $node->persist();
        $this->revision++; if (!$this->save(false)) throw new \RuntimeException('Build settings write failed.');
        return ['build' => $this->toArray(), 'node' => $node->toArray()];
    }
    public function fundingNode(): Node
    {
        if ($this->commitment_id) {
            $id = (new \yii\db\Query())->select('s.node_id')->from(['c' => 'economy_spending_commitment'])->innerJoin(['a' => 'economy_account'], 'a.id=c.account_id')->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->where(['c.id' => $this->commitment_id])->scalar(self::getDb());
            if ($id) return Node::requireOne($id);
        }
        if ($this->isRepair()) return $this->node;
        $parent = Node::requireOne($this->parent_id);
        return $parent->owner_user_id == $this->owner_user_id ? $parent : $this->node;
    }
    private static function fund(int $user, Node $source, int $account, Money $amount, bool $topUp, string $operation): void
    {
        $available = (new BudgetSpending(self::getDb()))->available($account);
        $gap = $amount->compare($available) > 0 ? $amount->subtract($available) : Money::parse('0');
        if ($gap->isZero()) return;
        if (!$topUp) throw new GameError('INSUFFICIENT_BUDGET', 'В бюджете недостаточно кредитов. Разрешите списать недостающую сумму с личного баланса.', 422);
        (new BudgetFunding(self::getDb()))->contribute($user, (int)$source->id, $gap, 'Оплата строительства', $operation);
    }
    public function finish(string $operation, bool $cancel = false): void
    {
        self::requireTransaction(); $this->assertOpen();
        if ($cancel) BuildClock::checkpoint($this, time());
        if (!$cancel && (int)$this->worked_seconds < (int)$this->required_seconds) return;
        BuildPayment::settle($this, $operation);
        if ($cancel && !$this->isRepair()) BuildPayment::refundCancelled($this, $operation);
        if ($cancel && !$this->isRepair()) BuildMaterials::refund((int)$this->owner_user_id, Json::decode($this->materials_json), $operation, (int)$this->parent_id);
        BuildWork::closeAll((int)$this->id);
        $this->status = $cancel ? 'cancelled' : 'completed'; $this->finished_at = time(); $this->revision++;
        if ($this->hasAttribute('active_node_id')) $this->active_node_id = null;
        if (!$this->save(false)) throw new \RuntimeException('Build completion failed.');
        $node = Node::requireOne($this->node_id);
        $node->status = $this->isRepair() ? 'active' : ($cancel ? 'archived' : 'active');
        if (!$cancel) $node->condition = $node->max_condition;
        if ($node->node_type === 'BUILDING' && !$this->isRepair()) $node->operational_status = $cancel ? 'archived' : 'active';
        if ($this->isRepair()) { if (!$node->save(false)) throw new \RuntimeException('Repair completion write failed.'); }
        else $node->persist();
        if ($cancel && !$this->isRepair() && $this->parent_id) {
            // A refreshed parent snapshot must supersede cached children containing this object.
            if (Node::updateAllCounters(['revision' => 1], ['id' => $this->parent_id]) !== 1)
                throw new \RuntimeException('Cancelled build parent update failed.');
        }
        if (!$cancel && !$this->isRepair()) {
            BuildFacilities::provision($node, $operation);
            domain\EconomyRuntime::fact(self::getDb(), (int)$this->owner_user_id, 'built:' . $node->template->code, 1, $operation);
        }
    }
    public static function listing(int $user, array $filter): \yii\data\ActiveDataProvider
    {
        return (new BuildSearch())->search($user, $filter);
    }
    public function fields(): array
    {
        return ['id', 'node_id', 'parent_id', 'owner_user_id', 'required_seconds', 'worked_seconds', 'labor_budget', 'status', 'revision', 'created_at',
            'world_time_rate' => function () { return WorldTime::rate((int)$this->node->root_id); },
            'server_time' => function () { return time(); },
            'job_kind' => function () { return $this->isRepair() ? 'repair' : 'construction'; },
            'initial_condition' => function () { return (int)$this->getAttribute('initial_condition'); },
            'max_condition' => function () { return (int)$this->node->max_condition; },
            'condition' => function () { return BuildStrength::forProject($this, (int)$this->worked_seconds); },
            'funding_node_id' => function () { return (int)$this->fundingNode()->id; },
            'budget_available' => function () { $accounts = (new EconomyHierarchy(self::getDb()))->accounts((int)$this->fundingNode()->id); return empty($accounts['budget']) ? '0.0000' : (new BudgetSpending(self::getDb()))->available((int)$accounts['budget']['id'])->decimal(); },
            'live_work' => function () { return $this->getWork()->select(['accounted_at', 'last_seen_at'])->andWhere(['ended_at' => null])->asArray()->all(); }];
    }
}
