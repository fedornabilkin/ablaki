<?php
namespace common\modules\world\admin;


use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\GameError;
use Yii;
use yii\filters\VerbFilter;
use yii\web\HttpException;

/** Each mapped controller is a separate RBAC route family, discovered by mdm. */
class WorldRecordController extends \yii\web\Controller
{
    public $table;
    public function getViewPath() { return Yii::getAlias('@common/modules/world/views/legacy/world-record'); }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET']]]]; }
    public function beforeAction($action)
    {
        \common\modules\world\models\admin\AdminAccess::requirePermission();
        if (!isset(WorldEntityCatalog::definitions()[$this->table])) throw new HttpException(404, 'Раздел не найден.');
        try { (new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')))->requireFlag('world_read'); }
        catch (GameError $e) { throw new HttpException($e->status, $e->getMessage()); }
        return parent::beforeAction($action);
    }
    protected function read($id, $key = []): array
    {
        return \common\modules\world\models\admin\LegacyRecordSearch::one($this->table, $id, $key);
    }
    public function actionIndex()
    {
        $table = $this->table; $definition = WorldEntityCatalog::definitions()[$table];
        $listing = \common\modules\world\models\admin\LegacyRecordSearch::search($table, Yii::$app->request->get('q', ''), Yii::$app->request->get('filter', []));
        $canCreate = $this instanceof WorldOfferController;
        return $this->render('index', compact('table', 'definition', 'canCreate') + $listing);
    }
    public function actionView($id = null, array $key = [])
    {
        return $this->render('view', ['table' => $this->table, 'definition' => WorldEntityCatalog::definitions()[$this->table], 'row' => $this->read($id, $key)]);
    }
}
