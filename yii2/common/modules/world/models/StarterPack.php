<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\support\GameError;

class StarterPack extends Record
{
    public static function tableName() { return 'world_start_claim'; }
    public static function claim(int $user, string $operation): void
    {
        self::requireTransaction();
        if (self::findOne($user) || (new \yii\db\Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'starter'])->exists(self::getDb())) return;
        $store = new CraftStorage(self::getDb()); $store->operationId = $operation;
        if ($store->isCanonical()) (new CanonicalInventory($store))->initialize($user);
        $items = ['classic-plank' => 40, 'classic-log' => 20, 'classic-stone' => 30, 'classic-rope' => 12,
            'classic-water' => 20, 'world-carrot-seed' => 10, 'world-shovel' => 1, 'classic-pickaxe' => 1,
            'classic-axe' => 1, 'classic-hammer' => 1, 'classic-space-elixir' => 1];
        $profile = domain\EconomyRuntime::current(self::getDb());
        if ($profile) $items = $profile['profile']['starter']['items'];
        foreach ($items as $code => $quantity) {
            $item = CraftItem::find()->where(['code' => $code, 'active' => 1])->asArray()->one();
            if (!$item) throw new GameError('STARTER_NOT_READY', 'Стартовый каталог ещё не установлен: ' . $code, 503);
            $store->move($user, $item, $quantity);
        }
        self::getDb()->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'starter', 'quantity' => array_sum($items), 'credit_change' => 0, 'created_at' => time()])->execute();
        $claim = new self(); $claim->setAttributes(['user_id' => $user, 'created_at' => time()], false);
        if (!$claim->save(false)) throw new \RuntimeException('Starter claim write failed.');
        domain\EconomyRuntime::fact(self::getDb(), $user, 'starter_claimed', 1, $operation);
    }
    public static function enter(int $user, int $city, string $operation): array
    {
        $parent = Node::readable($city, $user);
        if ((int)$parent->hierarchy_level !== 3 || (int)$parent->root_id !== Registry::worldId()) throw new GameError('INVALID_CITY', 'Выберите город действующего мира.', 422);
        $membership = Membership::forUser($user);
        self::claim($user, $operation);
        (new domain\ActorResolver(self::getDb()))->player($user);
        if ($membership && Node::requireOne($membership->starter_site_id)->status !== 'archived') return ['node' => Node::requireOne($membership->starter_site_id)->toArray()];
        $template = NodeTemplate::requireOne(['code' => 'starter-site']);
        $build = Build::begin($user, ['parent_id' => $city, 'template_id' => $template->id, 'name' => 'Стоянка', 'labor_budget' => '0'], $operation, true);
        $membership = $membership ?: new Membership(); $membership->setAttributes(['user_id' => $user, 'world_id' => $parent->root_id,
            'starter_site_id' => $build->node_id, 'joined_at' => time(), 'grace_until' => time() + 86400], false);
        if (!$membership->save(false)) throw new \RuntimeException('Membership write failed.');
        domain\EconomyRuntime::fact(self::getDb(), $user, 'city_joined', 1, $operation);
        (new domain\WorldNights(self::getDb(), new domain\WorldFlags(self::getDb(), \Yii::$app->getModule('world'))))->joined($membership->getAttributes());
        $funding = (new domain\StarterFunding(self::getDb()))->claim($user, $city, (int)$build->node_id, $operation);
        return ['node' => $build->node->toArray(), 'build' => $build->toArray(), 'starter_funding' => $funding, 'changed_node_ids' => [$city, (int)$build->node_id]];
    }
}
