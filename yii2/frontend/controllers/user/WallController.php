<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 20:59
 */

namespace frontend\controllers\user;


use common\controllers\FrontendController;
use common\models\user\User;
use common\services\cookies\CookieService;

class WallController extends FrontendController
{

    public function actionIndex($login)
    {
        $user = User::findOne(['username' => $login]);

        new CookieService([
            'name' => $this->cookieParams['refovod']['name'],
            'value' => $user->id,
            'expire' => $this->cookieParams['refovod']['expire'],
        ]);

        return $this->render('index', ['user' => $user]);
    }
}