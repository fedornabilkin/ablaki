<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 04.11.2018
 * Time: 14:56
 */

namespace frontend\tests\functional;


use frontend\tests\FunctionalTester;

class AbstractTest
{
    public $login = 'fedornabilkin';
    public $password = '252525';
    public $loginForm = 'login-form';

    public function _before(FunctionalTester $I)
    {
//        $I->click('About');
//        $I->seeLink('About');
    }

    protected function loginNow(FunctionalTester $I)
    {
        $I->amOnRoute('user/login');
        $I->submitForm('#'.$this->loginForm, $this->formParamsLogin($this->login, $this->password));
    }

    /**
     * Возвращает массив для отправки данных в форму
     * @param string $formName
     * @param array $data $data['login'] = $login;
     * @return array
     */
    protected function formParams($formName, $data)
    {
        $form = [];
        foreach ($data as $key => $value){
            $form[$formName . '['.$key.']'] = $value;
        }

        return $form;
    }

    protected function formParamsLogin($login, $password)
    {
        $data['login'] = $login;
        $data['password'] = $password;
        return $this->formParams($this->loginForm, $data);
    }

//    protected function formParamsGame($formName, $kon, $count)
//    {
//        return [
//            $formName . '[kon]' => $kon,
//            $formName . '[count]' => $count,
//        ];
//    }
}