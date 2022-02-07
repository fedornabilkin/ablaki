<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:54
 */

namespace common\models\history;

interface HistorySaveInterface
{
    public function getHistoryType(): string;
}
