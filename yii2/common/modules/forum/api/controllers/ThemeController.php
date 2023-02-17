<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.02.2023
 * Time: 22:17
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Theme;
use yii\rest\ActiveController;

class ThemeController extends ActiveController
{
    public $modelClass = Theme::class;

    public function actions()
    {
        $actions = parent::actions();

        unset($actions['update'], $actions['delete']);
        return $actions;
    }
}
