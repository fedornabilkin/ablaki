<?php
namespace backend\controllers;

use backend\models\WorldCropForm;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldCultivation;
use common\modules\world\service\WorldFlags;
use common\services\game\GameError;
use Yii;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\HttpException;

/** Standard route RBAC is supplied by the backend's mdm access behavior. */
class WorldCropController extends \yii\web\Controller
{
    public function getViewPath() { return Yii::getAlias('@app/views/world-crop'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'create' => ['GET', 'POST'], 'update' => ['GET', 'POST'], 'delete' => ['POST']]]]; }
    public function beforeAction($action) { if (Yii::$app->user->isGuest) throw new HttpException(403, 'Войдите в админку.'); return parent::beforeAction($action); }
    private function service(): WorldCultivation { return new WorldCultivation(Yii::$app->db, new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')), new WorldAccessPolicy((int)Yii::$app->user->id, true)); }
    private function read($id): array
    {
        if (!ctype_digit((string)$id)) throw new HttpException(404, 'Культура не найдена.');
        $row = (new Query())->select(['r.*', 'c.code', 'c.name'])->from(['r' => 'world_crop_revision'])->innerJoin(['c' => 'world_crop'], '[[c.id]]=[[r.crop_id]]')->where(['c.id' => (int)$id])->orderBy(['r.version' => SORT_DESC])->one(Yii::$app->db);
        if (!$row) throw new HttpException(404, 'Культура не найдена.'); return $row;
    }
    public function actionIndex()
    {
        $q = Yii::$app->request->get('q', ''); if (!is_string($q) || mb_strlen($q) > 120) throw new HttpException(422, 'Некорректный поиск.');
        $query = (new Query())->from('world_crop'); if ($q !== '') $query->where(['or', ['like', 'name', $q], ['like', 'code', $q]]);
        $provider = new \yii\data\ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20], 'sort' => ['attributes' => ['id', 'name'], 'defaultOrder' => ['id' => SORT_DESC]]]);
        return $this->render('index', compact('provider', 'q'));
    }
    public function actionView($id) { return $this->render('view', ['row' => $this->read($id)]); }
    public function actionCreate() { return $this->edit(null); }
    public function actionUpdate($id) { return $this->edit($this->read($id)); }
    private function edit(?array $row)
    {
        $model = new WorldCropForm(); if ($row) $model->setAttributes($row);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($row) $model->code = $row['code'];
            try {
                $service = $this->service(); $input = $service->publication($model->payload()); $user = (int)Yii::$app->user->id;
                $quote = $service->preview($user, 'publish', $input);
                $result = $service->execute($user, 'publish', $input, bin2hex(random_bytes(16)), $quote['quote_id'], (array)$quote['expected_revisions']);
                Yii::$app->session->setFlash('success', 'Новая версия опубликована. Текущие посевы сохраняют прежние правила.');
                return $this->redirect(['view', 'id' => $result['crop_id']]);
            } catch (GameError $e) { $model->addError('name', $e->getMessage()); }
        }
        $items = (new Query())->select(['name', 'id'])->from('craft_item')->where(['active' => 1, 'storage_kind' => 'none'])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(Yii::$app->db);
        return $this->render('form', compact('model', 'row', 'items'));
    }
    public function actionDelete($id)
    {
        $row = $this->read($id); $input = ['crop_id' => (int)$row['crop_id']]; $service = $this->service(); $user = (int)Yii::$app->user->id;
        try { $quote = $service->preview($user, 'withdraw', $input); $service->execute($user, 'withdraw', $input, bin2hex(random_bytes(16)), $quote['quote_id'], (array)$quote['expected_revisions']); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
        return $this->redirect(['view', 'id' => $id]);
    }
}
