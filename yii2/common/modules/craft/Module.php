<?php

namespace common\modules\craft;

use common\helpers\App;
use Yii;
use yii\i18n\PhpMessageSource;

/**
 * craft module definition class
 */
class Module extends \yii\base\Module
{
    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (!isset(Yii::$app->i18n->translations['craft*'])) {
            Yii::$app->i18n->translations['craft*'] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => 'ru',
                'basePath' => __DIR__ . '/messages',
            ];
        }

        App::urlManager()->addRules(require __DIR__ . '/config/urlRules.php');
    }
}
