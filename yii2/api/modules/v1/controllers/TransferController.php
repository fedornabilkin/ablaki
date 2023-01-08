<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 12:21
 */

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;

class TransferController extends \common\modules\exchange\api\controllers\TransferController
{
    use AuthTrait;
}
