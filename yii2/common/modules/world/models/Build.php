<?php
namespace common\modules\world\models;

use common\modules\world\modules\economy\models\domain\BudgetSpending;
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
    public static function begin(int $user, array $input, string $operation): self
    {
        self::requireTransaction();
        $parent = Node::readable($input['parent_id'], $user);
        if ((int)$parent->hierarchy_level >= 4) $parent->requireOwner($user);
        $template = NodeTemplate::requireOne($input['template_id']);
        if (!$template->player_buildable || !$template->enabled || !$template->permits($parent)) throw new GameError('INVALID_TEMPLATE', 'Этот объект здесь строить нельзя.', 422);
        if ((int)$template->hierarchy_level === 4 && (int)$parent->hierarchy_level !== 3) throw new GameError('INVALID_PARENT', 'Стоянка строится в поселении.', 422);
        $budget = Money::parse($input['labor_budget']);
        if ($budget->isNegative()) throw new GameError('INVALID_BUDGET', 'Бюджет не может быть отрицательным.', 422);
        // A player cannot spend a town's budget merely by owning a new plot there.
        if (!$budget->isZero()) $parent->requireOwner($user);
        $hold = null;
        if (!$budget->isZero()) {
            $accounts = (new EconomyHierarchy(self::getDb()))->provision((int)$parent->id, $operation);
            if ((int)(new EconomyHierarchy(self::getDb()))->financialNode((int)$parent->id) !== (int)$parent->id)
                throw new GameError('PARENT_BUDGET_REQUIRED', 'Для оплаты нужен собственный бюджет непосредственного родителя.', 422);
            $hold = (new BudgetSpending(self::getDb()))->reserve((int)$accounts['budget']['id'], $budget, 'Строительство: ' . $template->name, $operation);
        }
        BuildMaterials::consume($user, $template->materials(), $operation);
        $node = NodeFactory::create($template, $parent, $user, $input['name'], 'build-' . $operation);
        $build = new self(); $build->setAttributes(['node_id' => $node->id, 'parent_id' => $parent->id, 'owner_user_id' => $user,
            'required_seconds' => $template->build_seconds, 'worked_seconds' => 0, 'labor_budget' => $budget->decimal(), 'commitment_id' => $hold,
            'materials_json' => Json::encode((object)$template->materials()), 'status' => 'building', 'revision' => 1, 'created_at' => time()], false);
        if (!$build->save(false)) throw new \RuntimeException('Build write failed.');
        return $build;
    }
    public function assertOpen(): void
    {
        if ($this->status !== 'building') throw new GameError('BUILD_CLOSED', 'Стройка уже завершена или отменена.', 409);
        $parent = Node::requireOne($this->parent_id);
        if ($parent->status !== 'active' || (int)$this->node->parent_id !== (int)$parent->id) throw new GameError('BUILD_UNAVAILABLE', 'Площадка недоступна.', 409);
    }
    public function finish(string $operation, bool $cancel = false): void
    {
        self::requireTransaction(); $this->assertOpen();
        if ($cancel) BuildClock::checkpoint($this, time());
        if (!$cancel && (int)$this->worked_seconds < (int)$this->required_seconds) return;
        BuildPayment::settle($this, $operation);
        if ($cancel) BuildMaterials::refund((int)$this->owner_user_id, Json::decode($this->materials_json), $operation);
        BuildWork::closeAll((int)$this->id);
        $this->status = $cancel ? 'cancelled' : 'completed'; $this->finished_at = time(); $this->revision++;
        if (!$this->save(false)) throw new \RuntimeException('Build completion failed.');
        $node = Node::requireOne($this->node_id);
        $node->status = $cancel ? 'archived' : 'active';
        if ($node->node_type === 'BUILDING') $node->operational_status = $cancel ? 'archived' : 'active';
        $node->persist();
        if (!$cancel) BuildFacilities::provision($node, $operation);
    }
    public static function listing(int $user, array $filter): \yii\data\ActiveDataProvider
    {
        return (new BuildSearch())->search($user, $filter);
    }
    public function fields(): array
    {
        return ['id', 'node_id', 'parent_id', 'owner_user_id', 'required_seconds', 'worked_seconds', 'labor_budget', 'status', 'revision', 'created_at'];
    }
}
