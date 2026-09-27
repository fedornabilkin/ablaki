<?php
namespace backend\controllers;

use backend\components\WorldConfirmation;
use common\modules\world\model\WorldSlotForm;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldSlotEditor;
use common\services\game\GameError;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\HttpException;

class WorldSlotController extends Controller
{
    public function behaviors(): array
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'create' => ['GET', 'POST'], 'update' => ['GET', 'POST'], 'delete' => ['POST']]]];
    }
    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) throw new HttpException(403, 'Войдите в административную панель.');
        return parent::beforeAction($action);
    }
    private function flags(): WorldFlags { return new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); }
    private function editor(): WorldSlotEditor { return new WorldSlotEditor(Yii::$app->db, $this->flags()); }
    private function id($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) throw new HttpException(422, 'Некорректный идентификатор.');
        return (int)$value;
    }
    private function domain(callable $action)
    {
        try { $this->flags()->requireFlag('world_read'); return $action(); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
    }
    public function actionIndex()
    {
        return $this->domain(function () {
            $node = Yii::$app->request->get('node_id', ''); $q = Yii::$app->request->get('q', '');
            if (!is_string($q) || mb_strlen($q, 'UTF-8') > 120) throw new HttpException(422, 'Некорректный поиск.');
            $query = (new Query())->select(['s.*', 'node_id' => 'n.id', 'room_name' => 'n.name', 'owner_user_id' => 't.owner_user_id'])
                ->from(['s' => 'world_slot'])->innerJoin(['t' => 'craft_storage'], '[[t.id]]=[[s.storage_id]]')->innerJoin(['n' => 'world_node'], '[[n.id]]=[[t.node_id]]');
            if ($node !== '') $query->andWhere(['n.id' => $this->id($node)]);
            if (trim($q) !== '') $query->andWhere(['or', ['like', 's.code', trim($q)], ['like', 'n.name', trim($q)]]);
            $provider = new ActiveDataProvider(['query' => $query->orderBy(['t.id' => SORT_DESC, 's.position' => SORT_ASC]),
                'key' => static function (array $row): string { return $row['storage_id'] . ':' . $row['position']; },
                'pagination' => ['pageSize' => 20, 'pageSizeLimit' => [20, 20]], 'sort' => false]);
            return $this->render('index', compact('provider', 'node', 'q'));
        });
    }
    public function actionView($node_id, $position)
    {
        return $this->domain(function () use ($node_id, $position) {
            $state = $this->editor()->state($this->id($node_id)); $position = $this->id($position);
            if (!isset($state['slots'][$position])) throw new HttpException(404, 'Место не найдено.');
            $model = new WorldSlotForm(); $model->populate($state, $position);
            return $this->render('view', compact('state', 'position', 'model'));
        });
    }
    public function actionCreate($node_id = null)
    {
        return $this->domain(function () use ($node_id) {
            if ($node_id === null) return $this->render('choose-room');
            return $this->edit($this->id($node_id), 0, 'create');
        });
    }
    public function actionUpdate($node_id, $position) { return $this->domain(function () use ($node_id, $position) { return $this->edit($this->id($node_id), $this->id($position), 'update'); }); }
    public function actionDelete($node_id, $position) { return $this->domain(function () use ($node_id, $position) { return $this->edit($this->id($node_id), $this->id($position), 'delete'); }); }
    private function edit(int $node, int $position, string $action)
    {
        $binding = $this->route . ':' . $node . ':' . $position;
        // Replay a completed deletion before looking up the deleted slot.
        if (Yii::$app->request->isPost && ($record = WorldConfirmation::submitted($binding))) {
            $this->editor()->execute((int)Yii::$app->user->id, $record);
            Yii::$app->session->setFlash('success', $action === 'delete' ? 'Место удалено.' : 'Место сохранено.');
            return $this->redirect(['index', 'node_id' => $node]);
        }
        $state = $this->editor()->state($node); $model = new WorldSlotForm(); $model->populate($state, $position);
        if ($state['room']['node']['node_type'] !== 'ROOM') throw new HttpException(422, 'Редактор изменяет места в комнатах. Уличные места предоставляются вместе со стоянкой.');
        if ($position && !isset($state['slots'][$position])) throw new HttpException(404, 'Место не найдено.');
        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post()) && $model->validate()) {
                if ((int)$model->node_id !== $node) throw new HttpException(422, 'Комната не соответствует адресу формы.');
                try {
                    $input = $model->payload($position); $quote = $this->editor()->preview((int)Yii::$app->user->id, $input, $action);
                    $record = WorldConfirmation::remember($binding, compact('input', 'quote', 'action'));
                    return $this->render('confirm', compact('record', 'node', 'position'));
                } catch (GameError $e) { $model->addError('code', $e->getMessage()); Yii::$app->response->statusCode = $e->status; }
            } else Yii::$app->response->statusCode = 422;
        }
        return $this->render('form', compact('model', 'state', 'position', 'action'));
    }
}
