<?php
namespace common\modules\world\controllers\legacy;

use common\modules\world\models\admin\WorldCropForm;
use common\modules\world\models\domain\WorldAccessPolicy;
use common\modules\world\models\domain\WorldCultivation;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\GameError;
use Yii;
use common\modules\world\models\admin\LegacyLists;
use yii\filters\VerbFilter;
use yii\web\HttpException;

/** Standard route RBAC is supplied by the backend's mdm access behavior. */
class WorldCropController extends \yii\web\Controller
{
    public function getViewPath() { return Yii::getAlias('@common/modules/world/views/legacy/world-crop'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'create' => ['GET', 'POST'], 'update' => ['GET', 'POST'], 'delete' => ['POST']]]]; }
    public function beforeAction($action) { \common\modules\world\models\admin\AdminAccess::requirePermission(); return parent::beforeAction($action); }
    private function service(): WorldCultivation { return new WorldCultivation(Yii::$app->db, new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')), new WorldAccessPolicy((int)Yii::$app->user->id, true)); }
    private function read($id): array
    {
        if (!ctype_digit((string)$id)) throw new HttpException(404, 'Культура не найдена.');
        $row = LegacyLists::crop((int)$id);
        if (!$row) throw new HttpException(404, 'Культура не найдена.'); return $row;
    }
    public function actionIndex()
    {
        $q = Yii::$app->request->get('q', ''); if (!is_string($q) || mb_strlen($q) > 120) throw new HttpException(422, 'Некорректный поиск.');
        $provider = LegacyLists::crops($q);
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
        $items = LegacyLists::items();
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
