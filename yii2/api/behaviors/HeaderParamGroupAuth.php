<?php

namespace api\behaviors;

use common\models\GroupSession;
use common\models\Session;
use yii\filters\auth\QueryParamAuth;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;
use yii\web\User;

/**
 * @deprecated
 */
class HeaderParamGroupAuth extends QueryParamAuth
{

    /**
     * @var string the parameter name for passing the access token
     */
    public $tokenParam = 'session-token';

    /**
     * @param User $user
     * @param Request $request
     * @param Response $response
     * @return null|IdentityInterface
     * @throws UnauthorizedHttpException
     */
    public function authenticate($user, $request, $response)
    {
        $accessToken = $request->getHeaders()->get($this->tokenParam);
        if (is_string($accessToken)) {
            /** @var Session $identity */
            $identity = $user->loginByAccessToken($accessToken, get_class($this));
            if ($identity !== null) {
                return $identity;
            }
        }

        return $this->loginAsNew($user, $response);
    }

    /**
     * @param User $user
     * @param Response $response
     * @return mixed
     */
    private function loginAsNew($user, $response)
    {
        /** @var Session $identity */
        $identity = new $user->identityClass;
        $identity->accessToken = $identity->generateSessionId();
        $identity->groupSession = new GroupSession();
        $identity->save();

        if ($user->login($identity)) {
            $response->headers->set($this->tokenParam, $identity->accessToken);
            return $identity;
        }

        return null;
    }

}
