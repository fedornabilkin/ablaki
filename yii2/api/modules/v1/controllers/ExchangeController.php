<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 09.01.2022
 * Time: 18:11
 */

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;

class ExchangeController extends \common\modules\exchange\api\controllers\ExchangeController
{
    use AuthTrait;
}
