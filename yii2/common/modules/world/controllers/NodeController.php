<?php
namespace common\modules\world\controllers;

class NodeController extends CrudController
{
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
