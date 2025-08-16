<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 19:26
 */

namespace api\modules\v1;

use common\helpers\App;
use Yii;
use yii\i18n\PhpMessageSource;

class Module extends \yii\base\Module
{
    public function init()
    {
        parent::init();

//        if (!isset(Yii::$app->i18n->translations['app*'])) {
//            Yii::$app->i18n->translations['app*'] = [
//                'class' => PhpMessageSource::class,
//                'sourceLanguage' => 'ru',
//                'basePath' => __DIR__ . '/messages',
//            ];
//        }

        App::urlManager()->addRules(require __DIR__ . '/config/urlRules.php');
    }
}
