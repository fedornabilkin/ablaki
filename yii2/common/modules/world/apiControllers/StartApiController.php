<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\StarterPack;
use Yii;

class StartApiController extends CoreController
{
    public function actionIndex(): array
    {
        $member = \common\modules\world\models\Membership::forUser((int)Yii::$app->user->id);
        $node = $member ? \common\modules\world\models\Node::requireOne($member->starter_site_id) : null;
        $funding = new \common\modules\world\models\domain\StarterFunding(Yii::$app->db);
        $city = Yii::$app->request->get('node_id', Yii::$app->request->get('city_id')); $preview = null;
        if ($city !== null) {
            $parent = \common\modules\world\models\Node::readable($this->id($city), (int)Yii::$app->user->id);
            if ((new \common\modules\world\models\domain\StarterReserve(Yii::$app->db))->installed()) $preview = (new \common\modules\world\models\domain\StarterReserve(Yii::$app->db))->availability((int)$parent->id, (int)Yii::$app->user->id);
            elseif ((int)$parent->hierarchy_level === 3) $preview = $funding->preview((int)$parent->id);
        }
        return ['node' => $node && $node->status !== 'archived' ? $node->toArray() : null,
            'funding' => $preview, 'starter_funding' => $funding->forUser((int)Yii::$app->user->id)];
    }
    public function actionCreate(): array
    {
        $city = $this->id(Yii::$app->request->post('city_id'));
        return $this->command('world.start', ['city_id' => $city], function ($operation) use ($city) {
            return StarterPack::enter((int)Yii::$app->user->id, $city, $operation);
        });
    }
    public function actionReserve($id): array
    {
        $node = $this->id($id); $body = Yii::$app->request->bodyParams;
        if (!is_string($body['payout_cr'] ?? null) || !is_string($body['add_amount_cr'] ?? null)) throw new \common\modules\world\support\GameError('INVALID_STARTER_RESERVE', 'Укажите выплату и сумму пополнения резерва.', 422);
        $admin = Yii::$app->user->can('world-manage');
        return $this->command('world.start.reserve', ['node_id' => $node, 'payout_cr' => $body['payout_cr'], 'add_amount_cr' => $body['add_amount_cr']], static function ($op) use ($node, $body, $admin) {
            return (new \common\modules\world\models\domain\StarterReserve(Yii::$app->db))->configure((int)Yii::$app->user->id, $node, $body['payout_cr'], $body['add_amount_cr'], $op, $admin);
        });
    }
    protected function verbs() { return parent::verbs() + ['reserve' => ['POST']]; }
}
