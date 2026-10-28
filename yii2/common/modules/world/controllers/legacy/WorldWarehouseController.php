<?php
namespace common\modules\world\controllers\legacy;

use common\modules\world\models\domain\WarehousePolicyEditor;
use common\modules\world\support\GameError;
use Yii;
use common\modules\world\models\admin\LegacyLists;
use yii\filters\VerbFilter;
use yii\web\HttpException;

class WorldWarehouseController extends \yii\web\Controller
{
    public function getViewPath() { return Yii::getAlias('@common/modules/world/views/legacy/world-warehouse'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'update' => ['GET', 'POST']]]]; }
    public function beforeAction($action) { \common\modules\world\models\admin\AdminAccess::requirePermission(); return parent::beforeAction($action); }
    private function read($id): array
    {
        if (!ctype_digit((string)$id)) throw new HttpException(404, 'Склад не найден.');
        $row = LegacyLists::warehouse((int)$id); if (!$row) throw new HttpException(404, 'Склад не найден.'); return $row;
    }
    public function actionIndex()
    {
        $q = Yii::$app->request->get('q', ''); if (!is_string($q) || mb_strlen($q) > 120) throw new HttpException(422, 'Некорректный поиск.');
        $provider = LegacyLists::warehouseList($q);
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
