<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 20:44
 */

namespace api\filters;

use common\services\user\PresenceService;
use yii\filters\auth\HttpBearerAuth;

class Auth extends HttpBearerAuth
{
    public function authenticate($user, $request, $response)
    {
        $identity = parent::authenticate($user, $request, $response);
        if ($identity !== null) {
            PresenceService::recordActivity((int)$identity->getId());
        }
        return $identity;
    }
}
