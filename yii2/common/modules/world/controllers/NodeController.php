<?php
namespace common\modules\world\controllers;

class NodeController extends CrudController
{
    public function behaviors(): array { $rules = parent::behaviors(); $rules['verbs']['actions']['starter-reserve'] = ['POST']; return $rules; }
    public function allowAction(): array { return array_merge(parent::allowAction(), ['starter-reserve']); }
    public function actionStarterReserve()
    {
        $request = \Yii::$app->request; $node = filter_var($request->post('node_id'), FILTER_VALIDATE_INT); $key = $request->post('request_key');
        try {
            if (!$node || !is_string($key) || !is_string($request->post('payout_cr')) || !is_string($request->post('add_amount_cr'))) throw new \common\modules\world\support\GameError('INVALID_STARTER_RESERVE', 'Проверьте поселение и суммы.', 422);
            $user = (int)\Yii::$app->user->id; $payload = ['node_id' => $node, 'payout_cr' => $request->post('payout_cr'), 'add_amount_cr' => $request->post('add_amount_cr')];
            $result = \common\modules\world\models\Command::run($user, $key, 'world.start.reserve', $payload, static function ($op) use ($user, $node, $payload) {
                return (new \common\modules\world\models\domain\StarterReserve(\Yii::$app->db))->configure($user, $node, $payload['payout_cr'], $payload['add_amount_cr'], $op, true);
            }, true);
            \Yii::$app->session->setFlash('success', $result['message']);
        } catch (\common\modules\world\support\GameError $error) { \Yii::$app->session->setFlash('error', $error->getMessage()); }
        return $this->redirect(['index']);
    }
    public function actionIndex()
    {
        $search = new $this->searchClass();
        $ready = self::ready();
        return $this->render('index', ['searchModel' => $search, 'dataProvider' => $search->search(\Yii::$app->request->queryParams),
            'financialOverview' => $ready ? (new \common\modules\world\models\domain\WorldBalanceOverview(\Yii::$app->db))->report() : null]);
    }
    private static function ready(): bool
    {
        return \Yii::$app->db->schema->getTableSchema('world_economy_current') !== null
            && \Yii::$app->db->schema->getTableSchema('world_registry') !== null
            && \common\modules\world\modules\economy\models\domain\WalletSchema::ready(\Yii::$app->db)
            && (bool)(new \yii\db\Query())->from('world_registry')->where(['id' => 1])->exists(\Yii::$app->db);
    }
    public $modelClass = \common\modules\world\models\Node::class;
    public $searchClass = \common\modules\world\models\NodeSearch::class;
    public $title = 'Объекты мира';
    public $columns = ['id', 'name', 'parent_id', 'template_id', 'hierarchy_level', 'owner_user_id', 'status'];
    public $fields = ['name', 'code', 'slug', 'parent_id', 'template_id', 'owner_user_id', 'visibility', 'settings_json'];
}
