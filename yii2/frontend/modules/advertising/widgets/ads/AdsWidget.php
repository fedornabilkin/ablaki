<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 31.07.2018
 * Time: 21:35
 */

namespace frontend\modules\advertising\widgets\ads;


use common\widgets\AbstractWidget;
use frontend\modules\advertising\models\Advertising;
use frontend\modules\advertising\widgets\ads\asset\AdsAsset;

class AdsWidget extends AbstractWidget
{
    private $amount = 0.01;
    static private $company;

    public function run()
    {
        $this->getCompany();
        $item = array_shift(self::$company);
        if(!$item){
            return '';
        }

        $this->setCompanyView($item);

        return $this->render('index', compact('item'));
    }

    protected function setCompanyView($item)
    {
        /** @var $item Advertising */
        $item->updateCounters(['credit' => 0 - $this->amount, 'views' => 1]);
    }

    protected function getCompany()
    {
        if(self::$company){
            return self::$company;
        }

        $items = Advertising::find()
//            ->where(['status' => Advertising::ADV_STATUS_ACTIVE, 'approve' => Advertising::ADV_APPROVE_YES])
            ->where(['status' => Advertising::ADV_STATUS_ACTIVE])
            ->andWhere(['>', 'credit', 0])
            ->all();

        shuffle($items);
        return self::$company = $items;
    }

    public function registerAssets($view)
    {
        AdsAsset::register($view);
    }
}