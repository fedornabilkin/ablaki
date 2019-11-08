<?php

namespace frontend\tests\functional\home;

use frontend\tests\functional\AbstractTest;
use frontend\tests\FunctionalTester;

class HomeCest extends AbstractTest
{
    public function checkOpen(FunctionalTester $I)
    {
        $I->amOnPage(\Yii::$app->homeUrl);
        $I->see(\Yii::$app->name, 'h1');
    }
}