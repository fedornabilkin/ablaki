<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 21:28
 */

namespace frontend\widgets\user;


use common\widgets\AbstractWidget;

class Profile extends AbstractWidget
{

    public $user;

    public function run()
    {
        return $this->render('profile', ['user' => $this->user]);
    }

    public function registerAssets($view){}
}