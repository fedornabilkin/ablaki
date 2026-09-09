<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 17:54
 */

namespace common\models;

/**
 * @property $id
 */
interface ModelNameInterface
{
    public function name(): string;
}
