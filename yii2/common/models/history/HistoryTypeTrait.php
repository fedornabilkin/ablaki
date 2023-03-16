<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:57
 */

namespace common\models\history;

/**
 * @property $historyType
 */
trait HistoryTypeTrait
{
    public function getHistoryType(): string
    {
        return $this->historyType ?? static::class;
    }
}
