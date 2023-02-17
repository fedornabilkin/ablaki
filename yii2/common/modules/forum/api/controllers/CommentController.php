<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:02
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Comment;
use yii\rest\ActiveController;

class CommentController extends ActiveController
{
    public $modelClass = Comment::class;

    public function actions()
    {
        $actions = parent::actions();

        unset($actions['update'], $actions['delete']);
        return $actions;
    }
}
