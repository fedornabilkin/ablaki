<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 04.11.2018
 * Time: 14:49
 */

namespace frontend\tests\functional\games;


use frontend\tests\functional\AbstractTest;
use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class GamesUserPagesCest extends AbstractTest
{
    public function _before(FunctionalTester $I)
    {
        parent::_before($I);
        $this->loginNow($I);
    }

    public function checkOrel(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/games/orel']));
        $I->see(\Yii::t('games', 'Game Orels'), 'h1');

        $kon = 10;
        $count = 1;
//        $I->submitForm('#game-create', $this->formParams('GameOrel', ['kon' => $kon, 'count' => $count]));
//        $I->see(Yii::t('games', 'Created {count}x{kon}', ['count' => $count, 'kon' => $kon]));

        $I->amOnPage(Url::to(['/games/orel/my']));
        $I->see(\Yii::t('games', 'My games'), 'h1');

        $I->amOnPage(Url::to(['/games/orel/history']));
        $I->see(\Yii::t('games', 'Games complete'), 'h1');

        $I->amOnPage(Url::to(['/games/orel/last']));
        $I->see(\Yii::t('games', 'Games complete'), 'h1');
    }

    public function checkSaper(FunctionalTester $I)
    {
        $I->amOnPage(Url::to(['/games/saper']));
        $I->see(\Yii::t('games', 'Saper'), 'h1');

        $I->amOnPage(Url::to(['/games/saper/my']));
        $I->see(\Yii::t('games', 'My games'), 'h1');

        $I->amOnPage(Url::to(['/games/saper/history']));
        $I->see(\Yii::t('games', 'Games complete'), 'h1');
    }
}