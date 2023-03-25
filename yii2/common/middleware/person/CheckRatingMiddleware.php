<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:12
 */

namespace common\middleware\person;

use common\exceptions\person\RatingException;
use common\middleware\AbstractDataMiddleware;
use common\middleware\AbstractMiddleware;
use yii\db\Exception;

/**
 * @property AbstractDataMiddleware $data
 */
class CheckRatingMiddleware extends AbstractMiddleware
{
    protected $person;

    /**
     * @return bool
     * @throws RatingException
     * @throws Exception
     */
    public function check(): bool
    {
        $this->person = self::$data->getPerson();

        if ($this->person->rating < self::$data->getNeedRating()) {
            throw new RatingException();
        }

        return parent::check();
    }

}
