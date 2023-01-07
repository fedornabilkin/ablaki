<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 19:26
 */

namespace api\modules\v1;

use common\helpers\App;

class Module extends \yii\base\Module
{
    public function init()
    {
        parent::init();

        App::urlManager()->addRules(require __DIR__ . '/config/urlRules.php');
    }
}
