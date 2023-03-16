<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:06
 */

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\modules\forum\api\controllers\CommentController;

class ForumCommentController extends CommentController
{
    use AuthTrait;

    public function authExceptAction(): array
    {
        return ['index', 'view'];
    }
}
