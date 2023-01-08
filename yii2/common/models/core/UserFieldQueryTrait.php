<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 14:37
 */

namespace common\models\core;

trait UserFieldQueryTrait
{
    protected $userFieldName = 'user_id';

    protected $buyerFieldName = 'user_buyer';

    /**
     * @return string
     */
    private function userFieldName(): string
    {
        return $this->userFieldName;
    }

    /**
     * @return string
     */
    private function buyerFieldName(): string
    {
        return $this->buyerFieldName;
    }
}
