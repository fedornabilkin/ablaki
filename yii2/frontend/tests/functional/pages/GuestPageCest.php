<?php
namespace frontend\tests\functional\pages;

use frontend\tests\functional\AbstractTest;
use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class GuestPageCest extends AbstractTest
{
    public function checkAbout(FunctionalTester $I)
    {
        $I->amOnRoute('site/about');
        $I->see('About', 'h1');
    }
//    public function checkIndex(FunctionalTester $I)
//    {
//        $I->amOnRoute(\Yii::$app->homeUrl);
//        $I->see(\Yii::$app->name, 'h1');
//    }

    public function checkWall(FunctionalTester $I)
    {
        $login = 'demix';
        $I->amOnPage(Url::to(['/user/wall', 'login' => $login]));
        $I->see(\Yii::t('app', 'User wall {attr}', ['attr' => $login]));
    }

    public function checkRegister(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/user/register']));
        $I->see(\Yii::t('user', 'Sign up'), 'h1');
    }

    public function checkLogin(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/user/login']));
        $I->see(\Yii::t('user', 'Sign in'), 'h1');
    }

    public function checkForgot(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/user/forgot']));
        $I->see(\Yii::t('user', 'Recover your password'), 'h1');
    }

    public function checkResend(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/user/resend']));
        $I->see(\Yii::t('user', 'Request new confirmation message'), 'h1');
    }
}
