<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 15:53
 */

namespace common\modules\games;


use yii\i18n\PhpMessageSource;

class Module extends \yii\base\Module
{
    public function init()
    {
        parent::init();

        if (!isset(\Yii::$app->i18n->translations['games*'])) {
            \Yii::$app->i18n->translations['games*'] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => 'ru',
                'basePath' => __DIR__ . '/messages',
            ];
        }
    }
}