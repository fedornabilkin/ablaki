<?php
namespace backend\controllers;

use backend\models\WorldPremisesForm;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldPremises;
use common\modules\world\service\WorldQuery;
use common\modules\world\service\WorldTree;
use common\services\game\CanonicalJson;
use common\services\game\GameError;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

/** Server-rendered editor over the same domain commands as the game API. */
class WorldAdminController extends Controller
{
    public function behaviors(): array
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => [
            'index' => ['GET'], 'view' => ['GET'], 'premises' => ['GET'], 'audit' => ['GET'], 'preview' => ['POST'], 'execute' => ['POST'],
        ]]];
    }

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) {
            throw new ForbiddenHttpException('Войдите в административную панель.');
        }
        return parent::beforeAction($action);
    }

    private function flags(): WorldFlags { return new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); }
    private function policy(): WorldAccessPolicy { return new WorldAccessPolicy((int)Yii::$app->user->id, true); }
    private function premises(): WorldPremises { return new WorldPremises(Yii::$app->db, $this->flags(), $this->policy()); }
    private function reader(): WorldQuery { return new WorldQuery(Yii::$app->db, $this->policy()); }

    private function domain(callable $action)
    {
        try { return $action(); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
    }

    private function id($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
            throw new HttpException(422, 'Некорректный идентификатор.');
        }
        return (int)$value;
    }

    private function filter(string $key, array $allowed = []): string
    {
        $value = Yii::$app->request->get($key, '');
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > 120 || ($allowed && $value !== '' && !in_array($value, $allowed, true))) {
            throw new HttpException(422, 'Некорректный фильтр.');
        }
        return trim($value);
    }

    private function provider(Query $query): ActiveDataProvider
    {
        return new ActiveDataProvider(['query' => $query->orderBy(['id' => SORT_DESC]), 'key' => 'id',
            'pagination' => ['pageSize' => 20, 'pageSizeLimit' => [20, 20]], 'sort' => false]);
    }

    public function actionIndex()
    {
        $flags = $this->flags()->capabilities(); $provider = null;
        $q = $this->filter('q'); $type = $this->filter('type', WorldTree::TYPES);
        if ($flags['schema_ready']) {
            $query = (new Query())->from('world_node');
            if ($q !== '') $query->andWhere(['like', 'name', $q]);
            if ($type !== '') $query->andWhere(['node_type' => $type]);
            $provider = $this->provider($query);
        }
        return $this->render('index', compact('flags', 'provider', 'q', 'type'));
    }

    public function actionView($id)
    {
        return $this->domain(function () use ($id) {
            $this->flags()->requireFlag('world_read'); $node = $this->reader()->node($this->id($id));
            $navigation = $this->reader()->navigation($node['id']);
            $children = $this->provider((new Query())->from('world_node')->where(['parent_id' => $node['id']]));
            return $this->render('view', compact('node', 'navigation', 'children'));
        });
    }

    public function actionPremises($id)
    {
        return $this->domain(function () use ($id) { return $this->catalogue($this->id($id), new WorldPremisesForm()); });
    }

    public function actionAudit()
    {
        return $this->domain(function () {
            $this->flags()->requireFlag('world_read');
            $q = $this->filter('q'); $node = Yii::$app->request->get('node_id', '');
            $query = (new Query())->from('world_audit');
            if ($q !== '') $query->andWhere(['like', 'reason', $q]);
            if ($node !== '') { $node = $this->id($node); $query->andWhere(['node_id' => $node]); }
            $provider = $this->provider($query);
            return $this->render('audit', compact('provider', 'q', 'node'));
        });
    }

    private function catalogue(int $id, WorldPremisesForm $model)
    {
        // Domain context decides which settlement this administrator may actually manage.
        $context = $this->premises()->listing((int)Yii::$app->user->id, $id, 1, '');
        $context['can_publish'] = $context['can_publish'] && \mdm\admin\components\Helper::checkRoute('/world-admin/preview') && \mdm\admin\components\Helper::checkRoute('/world-admin/execute');
        if ($id !== $context['settlement_id']) throw new HttpException(422, 'Выберите поселение.');
        $q = $this->filter('q'); $status = $this->filter('status', ['published', 'withdrawn']);
        $query = (new Query())->select(['o.*', 'r.config_json', 'r.version', 'r.author_user_id'])
            ->from(['o' => 'world_premises_offer'])->innerJoin(['r' => 'world_template_revision'], '[[r.id]]=[[o.template_revision_id]]')
            ->where(['o.settlement_id' => $id]);
        if ($q !== '') $query->andWhere(['like', 'o.name', $q]);
        if ($status !== '') $query->andWhere(['o.status' => $status]);
        $provider = $this->provider($query);
        return $this->render('premises', compact('context', 'model', 'provider', 'q', 'status'));
    }

    public function actionPreview($id)
    {
        return $this->domain(function () use ($id) {
            $node = $this->id($id); $action = Yii::$app->request->post('operation');
            if (!in_array($action, ['publish', 'withdraw'], true)) throw new HttpException(422, 'Неизвестное действие.');
            $service = $this->premises();
            if ($action === 'publish') {
                $model = new WorldPremisesForm();
                if (!$model->load(Yii::$app->request->post()) || !$model->validate()) {
                    Yii::$app->response->statusCode = 422;
                    return $this->catalogue($node, $model);
                }
                try { $input = $model->payload($service, $node); }
                catch (GameError $e) {
                    $model->addError('name', $e->getMessage()); Yii::$app->response->statusCode = $e->status;
                    return $this->catalogue($node, $model);
                }
            } else {
                $reason = Yii::$app->request->post('reason');
                if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason, 'UTF-8') > 255) throw new HttpException(422, 'Укажите причину снятия предложения (до 255 символов).');
                $input = ['node_id' => $node, 'offer_id' => $this->id(Yii::$app->request->post('offer_id')), 'admin_reason' => trim($reason)];
            }
            $quote = $service->preview((int)Yii::$app->user->id, $input, $action);
            $quote['expected_revisions'] = (array)$quote['expected_revisions'];
            $pending = Yii::$app->session->get('world.admin.confirmations', []);
            // Keep completed entries too: a repeated POST uses the same idempotency key.
            $record = ['user_id' => (int)Yii::$app->user->id, 'action' => $action, 'input' => $input,
                'quote' => $quote, 'key' => bin2hex(random_bytes(16))];
            $record['digest'] = hash('sha256', CanonicalJson::encode($record));
            $pending[$quote['quote_id']] = $record;
            Yii::$app->session->set('world.admin.confirmations', array_slice($pending, -10, null, true));
            return $this->render('confirm', ['record' => $record]);
        });
    }

    public function actionExecute()
    {
        return $this->domain(function () {
            $token = Yii::$app->request->post('quote_id'); $digest = Yii::$app->request->post('digest');
            $pending = Yii::$app->session->get('world.admin.confirmations', []);
            $record = is_string($token) ? ($pending[$token] ?? null) : null;
            if (!$record || $record['user_id'] !== (int)Yii::$app->user->id || !is_string($digest) || !hash_equals($record['digest'], $digest)) {
                throw new HttpException(409, 'Подтверждение недоступно. Повторите предварительный расчёт.');
            }
            $quote = $record['quote'];
            $this->premises()->execute((int)Yii::$app->user->id, $record['key'], $record['input'],
                $quote['quote_id'], (array)$quote['expected_revisions'], $record['action']);
            Yii::$app->session->setFlash('success', $record['action'] === 'publish' ? 'Предложение опубликовано.' : 'Предложение снято с продажи.');
            return $this->redirect(['premises', 'id' => $record['input']['node_id']]);
        });
    }
}
