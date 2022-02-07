<?php

namespace common\modules\exchange;

use Yii;
use yii\i18n\PhpMessageSource;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        parent::init();

        if (!isset(Yii::$app->i18n->translations['exchange*'])) {
            Yii::$app->i18n->translations['exchange*'] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => 'ru',
                'basePath' => __DIR__ . '/messages',
            ];
        }
    }
}
