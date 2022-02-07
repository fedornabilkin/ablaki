<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 23:06
 */

namespace common\modules\exchange\middleware;

use common\middleware\DataMiddleware;
use common\middleware\dto\Request;
use common\modules\exchange\api\requests\CreateRequest;

/**
 *
 */
class ExchangeDataMiddleware extends DataMiddleware
{
    /**
     * @var CreateRequest
     */
    protected $request;

    public function __construct(Request $request, array $config = [])
    {
        parent::__construct($config);
        $this->request = $request;
    }

    public function getNeedCredit(): int
    {
        return (int)$this->request->credit * $this->request->count;
    }

    public function getNeedBalance(): int
    {
        return (int)$this->request->amount * $this->request->count;
    }
}
