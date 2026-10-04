<?php
namespace backend\controllers;

use common\modules\world\service\WarehousePolicyEditor;
use common\services\game\GameError;
use Yii;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\HttpException;

class WorldWarehouseController extends \yii\web\Controller
{
    public function getViewPath() { return Yii::getAlias('@app/views/world-warehouse'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'update' => ['GET', 'POST']]]]; }
    public function beforeAction($action) { if (Yii::$app->user->isGuest) throw new HttpException(403, 'Войдите в админку.'); return parent::beforeAction($action); }
    private function query(): Query
    {
        return (new Query())->select(['s.id', 's.node_id', 's.owner_user_id', 's.capacity', 's.status', 'n.name', 'p.initial_capacity', 'p.max_capacity', 'p.base_price', 'p.revision'])->from(['s' => 'craft_storage'])
            ->innerJoin(['p' => 'world_warehouse_policy'], '[[p.storage_id]]=[[s.id]]')->innerJoin(['n' => 'world_node'], '[[n.id]]=[[s.node_id]]');
    }
    private function read($id): array
    {
        if (!ctype_digit((string)$id)) throw new HttpException(404, 'Склад не найден.');
        $row = $this->query()->where(['s.id' => (int)$id])->one(Yii::$app->db); if (!$row) throw new HttpException(404, 'Склад не найден.'); return $row;
    }
    public function actionIndex()
    {
        $q = Yii::$app->request->get('q', ''); if (!is_string($q) || mb_strlen($q) > 120) throw new HttpException(422, 'Некорректный поиск.');
        $query = $this->query(); if ($q !== '') $query->andWhere(['like', 'n.name', $q]);
        $provider = new \yii\data\ActiveDataProvider(['query' => $query, 'key' => 'id', 'pagination' => ['pageSize' => 20], 'sort' => false]);
        return $this->render('index', compact('provider', 'q'));
    }
    public function actionView($id)
    {
        $row = $this->read($id);
        return $this->render('view', compact('row'));
    }
    public function actionUpdate($id)
    {
        $row = $this->read($id);
        $model = new \yii\base\DynamicModel(['max_capacity' => $row['max_capacity'], 'base_price' => $row['base_price'], 'revision' => $row['revision'], 'reason' => '']);
        $model->addRule(['max_capacity', 'base_price', 'revision', 'reason'], 'required')->addRule(['max_capacity', 'revision'], 'integer', ['min' => 1])->addRule(['base_price', 'reason'], 'string', ['max' => 255]);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            try { (new WarehousePolicyEditor(Yii::$app->db))->save((int)Yii::$app->user->id, (int)$row['id'], (int)$model->revision, (int)$model->max_capacity, trim($model->base_price), trim($model->reason)); return $this->redirect(['view', 'id' => $id]); }
            catch (GameError $e) { $model->addError('reason', $e->getMessage()); }
            catch (\InvalidArgumentException | \OverflowException $e) { $model->addError('base_price', 'Некорректная цена.'); }
        }
        return $this->render('form', compact('row', 'model'));
    }
}
