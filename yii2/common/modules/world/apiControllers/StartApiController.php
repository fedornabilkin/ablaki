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
        $city = Yii::$app->request->get('city_id'); $preview = null;
        if ($city !== null) {
            $parent = \common\modules\world\models\Node::readable($this->id($city), (int)Yii::$app->user->id);
            if ((int)$parent->hierarchy_level === 3) $preview = $funding->preview((int)$parent->id);
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
}
