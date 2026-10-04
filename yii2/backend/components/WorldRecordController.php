<?php
namespace backend\components;

use common\modules\world\service\WorldFlags;
use common\services\game\GameError;
use Yii;
use yii\filters\VerbFilter;
use yii\web\HttpException;

/** Each mapped controller is a separate RBAC route family, discovered by mdm. */
class WorldRecordController extends \yii\web\Controller
{
    public $table;
    public function getViewPath() { return Yii::getAlias('@app/views/world-record'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET']]]]; }
    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) throw new HttpException(403, 'Войдите в админку.');
        if (!isset(WorldEntityCatalog::definitions()[$this->table])) throw new HttpException(404, 'Раздел не найден.');
        try { (new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')))->requireFlag('world_read'); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
        return parent::beforeAction($action);
    }
    protected function read($id, $key = []): array
    {
        $row = (new \yii\db\Query())->from($this->table)->where(WorldRelations::key($this->table, $id, $key))->one(Yii::$app->db);
        if (!$row) throw new HttpException(404, 'Запись не найдена.');
        return $row;
    }
    public function actionIndex()
    {
        $table = $this->table; $definition = WorldEntityCatalog::definitions()[$table];
        $schema = WorldRelations::schema($table); $q = Yii::$app->request->get('q', ''); $filters = Yii::$app->request->get('filter', []);
        if (!is_string($q) || mb_strlen($q, 'UTF-8') > 120 || !is_array($filters)) throw new HttpException(422, 'Некорректный поиск.');
        $fields = array_values(array_filter(array_keys($schema->columns), static function ($field) use ($schema) {
            return in_array($field, $schema->primaryKey, true) || substr($field, -3) === '_id' || in_array($field, ['status', 'kind', 'state'], true);
        }));
        $where = [];
        foreach ($filters as $field => $value) {
            if (!in_array($field, $fields, true)) throw new HttpException(422, 'Неизвестный фильтр.');
            if ($value === '') continue;
            WorldRelations::validate($table, $field, $value);
            $where[$field] = $value;
        }
        $provider = WorldRelations::provider($table, $where);
        if (trim($q) !== '') {
            $search = ['or'];
            foreach (array_intersect(['name', 'code', 'reason', 'status', 'kind'], array_keys($schema->columns)) as $field) $search[] = ['like', $field, trim($q)];
            if (ctype_digit($q) && isset($schema->columns['id'])) $search[] = ['id' => $q];
            $provider->query->andWhere(count($search) > 1 ? $search : '0=1');
        }
        $canCreate = $this instanceof WorldOfferController;
        return $this->render('index', compact('table', 'definition', 'provider', 'q', 'filters', 'fields', 'canCreate'));
    }
    public function actionView($id = null, array $key = [])
    {
        return $this->render('view', ['table' => $this->table, 'definition' => WorldEntityCatalog::definitions()[$this->table], 'row' => $this->read($id, $key)]);
    }
}
