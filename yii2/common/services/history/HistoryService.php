<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 21:46
 */

namespace common\services\history;

use Yii;

class HistoryService
{
    public const HT_EVERYDAY = 'everyday';
    public const HT_OREL = 'game_orel';
    public const HT_SAPER = 'game_saper';
    public const HT_DUEL = 'game_duel';

    public static function getTypes(): array
    {
        return [
            self::HT_EVERYDAY => Yii::t('app', self::HT_EVERYDAY),
            self::HT_OREL => Yii::t('app', self::HT_OREL),
            self::HT_SAPER => Yii::t('app', self::HT_SAPER),
            self::HT_DUEL => Yii::t('app', self::HT_DUEL),
        ];
    }
}
