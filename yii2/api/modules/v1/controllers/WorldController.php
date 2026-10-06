<?php
namespace api\modules\v1\controllers;

/** @deprecated Public routes are owned by the world module. */
class WorldController extends \common\modules\world\apiControllers\LegacyController
{
    public function createAction($id)
    {
        $routes = require \Yii::getAlias('@common/modules/world/config/legacyActions.php');
        if (!isset($routes[$id])) return parent::createAction($id);
        $module = \Yii::$app->getModule('world');
        return $module->createController('api-' . $routes[$id])[0]->createAction($id);
    }
}
