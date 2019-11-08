<?php

namespace frontend\tests\functional\home;

use frontend\tests\functional\AbstractTest;
use frontend\tests\FunctionalTester;
use common\fixtures\UserFixture;

class LoginCest extends AbstractTest
{
     /**
      * Load fixtures before db transaction begin
      * Called in _before()
      * @see \Codeception\Module\Yii2::_before()
      * @see \Codeception\Module\Yii2::loadFixtures()
      * @return array
      */
//    public function _fixtures()
//    {
//        return [
//            'user' => [
//                'class' => UserFixture::class,
//                'dataFile' => codecept_data_dir() . 'login_data.php'
//            ]
//        ];
//    }

    public function _before(FunctionalTester $I)
    {
        $I->amOnRoute('user/login');
    }

    public function checkEmpty(FunctionalTester $I)
    {
        $I->submitForm('#'.$this->loginForm, $this->formParamsLogin('', ''));
        // todo translate
        $I->seeValidationError('Необходимо заполнить «Логин».');
        $I->seeValidationError('Необходимо заполнить «Пароль».');
    }

    public function checkWrongPassword(FunctionalTester $I)
    {
        $I->submitForm('#'.$this->loginForm, $this->formParamsLogin('demix', 'wrong'));
        // todo translate
        $I->seeValidationError('Неправильный логин или пароль');
    }
    
    public function checkValidLogin(FunctionalTester $I)
    {
        $I->submitForm('#'.$this->loginForm, $this->formParamsLogin($this->login, $this->password));

        $I->see(\Yii::t('app', 'Logout'), 'form button[type=submit]');
        $I->see($this->login, 'a');

        $I->dontSeeLink(\Yii::t('app', 'Signup'));
        $I->dontSeeLink(\Yii::t('app', 'Login'));
    }
}
