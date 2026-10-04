<?php
namespace backend\components;

use backend\models\WorldGardenOfferForm;
use backend\models\WorldPremisesOfferForm;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldGarden;
use common\modules\world\service\WorldPremises;
use common\services\game\GameError;
use Yii;
use yii\db\Query;
use yii\web\HttpException;

class WorldOfferController extends WorldRecordController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions'] += ['create' => ['GET', 'POST'], 'update' => ['GET', 'POST'], 'delete' => ['POST']];
        return $behaviors;
    }
    private function service()
    {
        $flags = new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); $access = new WorldAccessPolicy((int)Yii::$app->user->id, true);
        return $this->table === 'world_premises_offer' ? new WorldPremises(Yii::$app->db, $flags, $access) : new WorldGarden(Yii::$app->db, $flags, $access);
    }
    private function domain(callable $call)
    {
        try { return $call(); } catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
    }
    private function confirm(int $id, array $input, string $action)
    {
        $quote = $this->service()->preview((int)Yii::$app->user->id, $input, $action);
        $record = WorldConfirmation::remember($this->route . ':' . $id, compact('input', 'action', 'quote'));
        return $this->render('confirm', ['record' => $record, 'definition' => WorldEntityCatalog::definitions()[$this->table], 'id' => $id]);
    }
    private function submit(int $id)
    {
        $record = WorldConfirmation::submitted($this->route . ':' . $id);
        if (!$record) return null;
        $result = $this->service()->execute((int)Yii::$app->user->id, $record['key'], $record['input'], $record['quote']['quote_id'], $record['quote']['expected_revisions'], $record['action']);
        Yii::$app->session->setFlash('success', $record['action'] === 'publish' ? 'Условия опубликованы. Прежние покупки сохранены.' : 'Предложение снято с публикации.');
        return $this->redirect(['view', 'id' => $result['offer_id'] ?? $id]);
    }
    public function actionCreate() { return $this->domain(function () { return $this->edit(null); }); }
    public function actionUpdate($id) { return $this->domain(function () use ($id) { return $this->edit($this->read($id)); }); }
    private function edit(?array $row)
    {
        $id = $row ? (int)$row['id'] : 0;
        if (Yii::$app->request->isPost && ($response = $this->submit($id))) return $response;
        if ($row && !WorldRelations::editable($this->table, $row)) throw new HttpException(409, 'Предложение уже снято. Откройте актуальное предложение или создайте новое.');
        $model = $this->table === 'world_premises_offer' ? new WorldPremisesOfferForm() : new WorldGardenOfferForm();
        if ($row) {
            if ($model instanceof WorldPremisesOfferForm) {
                $row['config_json'] = (new Query())->select('config_json')->from('world_template_revision')->where(['id' => $row['template_revision_id']])->scalar(Yii::$app->db);
                $model->populate($row);
            } else $model->setAttributes($row);
        } else {
            $model->settlement_id = Yii::$app->request->get('settlement_id');
            if ($model->settlement_id !== null) WorldRelations::validate($this->table, 'settlement_id', $model->settlement_id);
        }
        if (Yii::$app->request->isPost) {
            $loaded = $model->load(Yii::$app->request->post());
            if ($row) $model->settlement_id = $row['settlement_id'];
            if ($loaded && $model->validate()) {
                try {
                    if ($model instanceof WorldPremisesOfferForm) {
                        $input = $model->payload($this->service(), (int)$model->settlement_id);
                        if ($row) $input['replaces_offer_id'] = $id;
                    } else $input = ['node_id' => (int)$model->settlement_id, 'expected_offer_id' => $id, 'admin_reason' => $model->reason]
                        + $this->service()->input($model->getAttributes(), 'publish');
                    return $this->confirm($id, $input, 'publish');
                } catch (GameError $e) { $model->addError('name', $e->getMessage()); Yii::$app->response->statusCode = $e->status; }
            } else Yii::$app->response->statusCode = 422;
        }
        $settlements = (new Query())->select(['name', 'id'])->from('world_node')->where(['node_type' => 'SETTLEMENT', 'status' => 'active', 'visibility' => 'public'])
            ->andWhere(['or', ['owner_user_id' => null], ['owner_user_id' => Yii::$app->user->id]])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(Yii::$app->db);
        return $this->render('form', ['model' => $model, 'row' => $row, 'settlements' => $settlements, 'definition' => WorldEntityCatalog::definitions()[$this->table]]);
    }
    public function actionDelete($id)
    {
        return $this->domain(function () use ($id) {
            $row = $this->read($id); $id = (int)$row['id'];
            if ($response = $this->submit($id)) return $response;
            if (!WorldRelations::editable($this->table, $row)) throw new HttpException(409, 'Предложение уже снято.');
            $reason = Yii::$app->request->post('reason');
            if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason, 'UTF-8') > 255) throw new HttpException(422, 'Укажите причину удаления (до 255 символов).');
            $input = ['node_id' => (int)$row['settlement_id'], 'admin_reason' => trim($reason)];
            $input[$this->table === 'world_premises_offer' ? 'offer_id' : 'expected_offer_id'] = $id;
            return $this->confirm($id, $input, 'withdraw');
        });
    }
}
