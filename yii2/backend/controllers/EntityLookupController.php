<?php
namespace backend\controllers;

use backend\support\EntityDisplay;
use mdm\admin\components\Helper;
use Yii;
use yii\db\Query;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

class EntityLookupController extends Controller
{
    public function allowAction(): array { return ['index', 'cells']; }
    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest || !Yii::$app->user->can('p-admin')) throw new ForbiddenHttpException();
        return parent::beforeAction($action);
    }
    public function actionIndex($table, $q = '', $page = 1, $kind = null)
    {
        $route = is_string($table) ? (EntityDisplay::routes()[$table] ?? null) : null;
        if (!$route || !Helper::checkRoute($route . '/index')) throw new NotFoundHttpException();
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema || count($schema->primaryKey) !== 1 || !is_string($q) || strlen($q) > 120 || !ctype_digit((string)$page) || (int)$page < 1 || (int)$page > 10000) throw new NotFoundHttpException();
        $pk = $schema->primaryKey[0];
        $query = (new Query())->select(EntityDisplay::fields($table))->from($table);
        $where = ['or'];
        foreach (['name', 'label', 'username', 'title', 'code'] as $field) if (isset($schema->columns[$field])) $where[] = [Yii::$app->db->driverName === 'pgsql' ? 'ilike' : 'like', $field, $q];
        if ($q !== '' && count($where) > 1) $query->andWhere($where);
        if ($kind !== null && $table === 'craft_item' && in_array($kind, ['material', 'tool', 'station'], true)) $query->andWhere(['kind' => $kind]);
        $rows = $query->orderBy([$pk => SORT_ASC])->offset(((int)$page - 1) * 30)->limit(31)->all();
        return $this->asJson(['results' => array_map(static function ($row) use ($table, $pk) { return ['id' => $row[$pk], 'text' => EntityDisplay::label($table, $row)]; }, array_slice($rows, 0, 30)), 'pagination' => ['more' => count($rows) > 30]]);
    }
    public function actionCells($parent, $except = null, $page = 1)
    {
        if (!Helper::checkRoute('/world/node/view') || !ctype_digit((string)$parent) || !ctype_digit((string)$page) || (int)$page < 1 || (int)$page > 10000) throw new NotFoundHttpException();
        $node = \common\modules\world\models\Node::findOne((int)$parent);
        if (!$node) throw new NotFoundHttpException();
        $children = \common\modules\world\models\Node::find()->where(['parent_id' => $node->id])->andWhere(['<>', 'status', 'archived']);
        if ($except !== null && ctype_digit((string)$except)) $children->andWhere(['<>', 'id', (int)$except]);
        $occupied = [];
        foreach ((new \common\modules\world\models\domain\PlacementProvisioner(\Yii::$app->db))->equipmentCells((int)$node->id) as $cell) $occupied[$cell['x'] . ':' . $cell['y']] = 'Оборудование';
        foreach ($children->all() as $child) foreach (\common\modules\world\models\domain\WorldMapGeometry::cells($child->footprint_json, (int)$child->position_x, (int)$child->position_y) as $cell) $occupied[$cell['x'] . ':' . $cell['y']] = $child->name;
        $width = (int)$node->map_width; $total = $width * (int)$node->map_height; $cells = [];
        for ($index = ((int)$page - 1) * 200; $index < min($total, (int)$page * 200); $index++) {
            $x = (int)$node->map_origin_x + $index % $width; $y = (int)$node->map_origin_y + intdiv($index, $width); $key = $x . ':' . $y;
            $cells[] = ['key' => $key, 'x' => $x, 'y' => $y, 'occupied' => isset($occupied[$key]), 'name' => $occupied[$key] ?? ''];
        }
        return $this->asJson(['cells' => $cells, 'more' => (int)$page * 200 < $total]);
    }
}
