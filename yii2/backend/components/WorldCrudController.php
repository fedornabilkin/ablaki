<?php
namespace backend\components;

use common\modules\world\model\WorldNodeForm;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldNodeEditor;
use common\services\game\GameError;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\HttpException;

/** Each concrete controller is a separate standard mdm RBAC route family. */
abstract class WorldCrudController extends Controller
{
    abstract protected function nodeType(): string;
    public function getViewPath() { return Yii::getAlias('@app/views/world-node'); }
    public function behaviors(): array
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => [
            'index' => ['GET'], 'view' => ['GET'], 'create' => ['GET', 'POST'], 'update' => ['GET', 'POST'], 'delete' => ['POST'],
        ]]];
    }
    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) throw new HttpException(403, 'Войдите в административную панель.');
        return parent::beforeAction($action);
    }
    private function flags(): WorldFlags { return new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); }
    private function editor(): WorldNodeEditor { return new WorldNodeEditor(Yii::$app->db, $this->flags()); }
    private function id($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) throw new HttpException(422, 'Некорректный ID объекта.');
        return (int)$value;
    }
    private function read($id): array
    {
        $this->flags()->requireFlag('world_read'); $snapshot = $this->editor()->snapshot($this->id($id));
        if ($snapshot['node']['node_type'] !== $this->nodeType()) throw new HttpException(404, 'Объект не найден в этом разделе.');
        return $snapshot;
    }
    private function domain(callable $action)
    {
        try { return $action(); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
    }
    public function actionIndex()
    {
        return $this->domain(function () {
            $this->flags()->requireFlag('world_read');
            $q = Yii::$app->request->get('q', ''); $status = Yii::$app->request->get('status', 'active');
            if (!is_string($q) || mb_strlen($q, 'UTF-8') > 120 || !is_string($status) || !in_array($status, ['', 'active', 'archived'], true)) throw new HttpException(422, 'Некорректный фильтр.');
            $query = (new Query())->from('world_node')->where(['node_type' => $this->nodeType()]);
            if (trim($q) !== '') $query->andWhere(['or', ['like', 'name', trim($q)], ['like', 'code', trim($q)]]);
            if ($status !== '') $query->andWhere(['status' => $status]);
            $parent = Yii::$app->request->get('parent_id', ''); $owner = Yii::$app->request->get('owner_user_id', '');
            if ($parent !== '') $query->andWhere(['parent_id' => $this->id($parent)]);
            if ($owner !== '') $query->andWhere(['owner_user_id' => $this->id($owner)]);
            $provider = new ActiveDataProvider(['query' => $query, 'key' => 'id', 'pagination' => ['pageSize' => 20, 'pageSizeLimit' => [20, 20]],
                'sort' => ['attributes' => ['id', 'name', 'updated_at'], 'defaultOrder' => ['id' => SORT_DESC]]]);
            return $this->render('index', ['type' => $this->nodeType(), 'provider' => $provider, 'q' => $q, 'status' => $status, 'parent' => $parent, 'owner' => $owner]);
        });
    }
    public function actionView($id)
    {
        return $this->domain(function () use ($id) {
            $snapshot = $this->read($id); $model = new WorldNodeForm($this->nodeType()); $model->populate($snapshot);
            $children = new ActiveDataProvider(['query' => (new Query())->from('world_node')->where(['parent_id' => $snapshot['node']['id']])->orderBy(['id' => SORT_DESC]),
                'key' => 'id', 'pagination' => ['pageSize' => 20, 'pageSizeLimit' => [20, 20]], 'sort' => false]);
            return $this->render('view', compact('snapshot', 'model', 'children') + ['usage' => $this->editor()->usage((int)$snapshot['node']['id'])]);
        });
    }
    public function actionCreate() { return $this->domain(function () { return $this->edit(0, 'create'); }); }
    public function actionUpdate($id) { return $this->domain(function () use ($id) { return $this->edit($this->id($id), 'update'); }); }
    private function edit(int $id, string $action)
    {
        $this->flags()->requireFlag('world_read'); $model = new WorldNodeForm($this->nodeType()); $snapshot = $id ? $this->read($id) : null;
        if ($snapshot) $model->populate($snapshot);
        $binding = $this->route . ':' . $id;
        if (Yii::$app->request->isPost) {
            $record = WorldConfirmation::submitted($binding);
            if ($record) {
                $result = $this->editor()->execute((int)Yii::$app->user->id, $record);
                Yii::$app->session->setFlash('success', 'Объект сохранён.');
                return $this->redirect(['view', 'id' => $result['node_id']]);
            }
            if ($model->load(Yii::$app->request->post()) && $model->validate()) {
                try {
                    $input = $model->payload($id); $quote = $this->editor()->preview((int)Yii::$app->user->id, $input, $action);
                    $record = WorldConfirmation::remember($binding, compact('input', 'action', 'quote'));
                    return $this->render('confirm', ['record' => $record, 'type' => $this->nodeType(), 'id' => $id]);
                } catch (GameError $e) { $model->addError('name', $e->getMessage()); Yii::$app->response->statusCode = $e->status; }
            } else Yii::$app->response->statusCode = 422;
        } elseif (!$id && Yii::$app->request->get('parent_id') !== null) {
            $model->parent_id = $this->id(Yii::$app->request->get('parent_id'));
            if (in_array($this->nodeType(), ['ROOM', 'BED'], true)) $model->owner_user_id = $this->editor()->snapshot($model->parent_id)['node']['owner_user_id'];
        }
        return $this->render('form', compact('model', 'snapshot', 'id'));
    }
    public function actionDelete($id)
    {
        return $this->domain(function () use ($id) {
            $id = $this->id($id); $snapshot = $this->read($id); $binding = $this->route . ':' . $id;
            $record = WorldConfirmation::submitted($binding);
            if ($record) {
                $this->editor()->execute((int)Yii::$app->user->id, $record);
                Yii::$app->session->setFlash('success', 'Объект удалён в архив. История сохранена.');
                return $this->redirect(['index']);
            }
            $reason = Yii::$app->request->post('reason'); $revision = Yii::$app->request->post('revision');
            if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason, 'UTF-8') > 255) throw new HttpException(422, 'Укажите причину удаления (до 255 символов).');
            $input = ['id' => $id, 'revision' => $this->id($revision), 'reason' => trim($reason)]; $action = 'delete';
            $quote = $this->editor()->preview((int)Yii::$app->user->id, $input, $action);
            $record = WorldConfirmation::remember($binding, compact('input', 'action', 'quote'));
            return $this->render('confirm', ['record' => $record, 'type' => $snapshot['node']['node_type'], 'id' => $id]);
        });
    }
}
