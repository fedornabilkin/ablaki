<?php

namespace frontend\modules\advertising;

use yii\i18n\PhpMessageSource;

/**
 * advertising module definition class
 */
class Module extends \yii\base\Module
{
    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = 'frontend\modules\advertising\controllers';

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (!isset(\Yii::$app->i18n->translations[$this->id . '*'])) {
            \Yii::$app->i18n->translations[$this->id . '*'] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => 'ru',
                'basePath' => __DIR__ . '/messages',
            ];
        }
    }
}
