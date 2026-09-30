<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 10.08.2018
 * Time: 23:56
 */

namespace frontend\tests\functional\pages;


use frontend\tests\functional\AbstractTest;
use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class UserPageCest extends AbstractTest
{
    public function _before(FunctionalTester $I)
    {
        parent::_before($I);
        $this->loginNow($I);
    }

    public function checkBalance(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/balance']));
        $I->see(\Yii::t('app', 'History Balances'), 'h1');
    }

    public function checkAdvertising(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/advertising/company']));
        $I->see(\Yii::t('app', 'Advertisings'), 'h1');
    }
}