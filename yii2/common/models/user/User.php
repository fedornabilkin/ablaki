<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 19:31
 */

namespace common\models\user;

use common\services\cookies\CookieService;
use Yii;

class User extends \dektrium\user\models\User
{
    private $person;

    public $cookieParams;

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPerson()
    {
        return $this->hasOne(Person::class, ['user_id' => 'id'])->inverseOf('user');
    }

    /** @inheritdoc */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if ($insert) {
            if (!$this->person) {
                $this->person = Yii::createObject(Person::class);
            }

            if (\Yii::$app->id == 'app-frontend') {
                $this->cookieParams = \Yii::$app->params['cookies'];
                $this->setRefovod();
                $this->setReferrer();
            }

            $this->person->link('user', $this);
        }
    }

    /**
     * Устанавливает рефовода
     * @inheritdoc
     */
    public function setRefovod()
    {
        $service = new CookieService([
            'name' => $this->cookieParams['refovod']['name'],
        ]);

        $this->person->refovod = $service->getValue();
    }

    /**
     * Устанавливает реферрер
     * @inheritdoc
     */
    public function setReferrer()
    {
        $service = new CookieService([
            'name' => $this->cookieParams['referrer']['name'],
        ]);
        $this->person->referrer = $service->getValue();
    }
}
