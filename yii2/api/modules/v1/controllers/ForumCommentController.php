<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:06
 */

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use api\filters\Auth;
use common\modules\forum\api\controllers\CommentController;

class ForumCommentController extends CommentController
{
    use AuthTrait { behaviors as private configuredAuthBehaviors; }

    public function behaviors(): array
    {
        $behaviors = $this->configuredAuthBehaviors();
        // Public reads still resolve a supplied token for the per-viewer gift state.
        $behaviors[Auth::class]['optional'] = ['index', 'view', 'gifts'];
        $behaviors[Auth::class]['except'] = ['options'];
        return $behaviors;
    }
}
