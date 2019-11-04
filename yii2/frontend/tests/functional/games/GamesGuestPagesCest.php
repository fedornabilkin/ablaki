<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 04.11.2018
 * Time: 14:11
 */

namespace frontend\tests\functional\games;


use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class GamesGuestPagesCest
{
    public function checkGamesLast(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/games/saper/last']));
        $I->see(\Yii::t('games', 'Games complete'), 'h1');

        $I->amOnPage(Url::to(['/games/orel/last']));
        $I->see(\Yii::t('games', 'Games complete'), 'h1');
    }
}