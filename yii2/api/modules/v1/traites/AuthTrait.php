<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 12:26
 */

namespace api\modules\v1\traites;

use api\filters\Auth;

trait AuthTrait
{
    public function behaviors(): array
    {
        return array_merge($this->authParentBehaviors(), [
            Auth::class => [
                'class' => Auth::class,
                'except' => $this->authExceptAction(),
            ],
        ]);
    }

    public function authParentBehaviors()
    {
        return parent::behaviors();
    }

    public function authExceptAction(): array
    {
        return [];
    }
}
