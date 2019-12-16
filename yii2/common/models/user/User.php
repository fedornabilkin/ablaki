<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 19:31
 */

namespace common\models\user;

use common\models\Todo;
use common\services\cookies\CookieService;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;

/**
 * Class User
 * @package common\models\user
 *
 * @property ActiveQuery $person
 * @property ActiveQuery $todo
 */
class User extends \dektrium\user\models\User
{
    public $cookieParams;

    /**
     * @return ActiveQuery
     */
    public function getPerson(): ActiveQuery
    {
        return $this->hasOne(Person::class, ['user_id' => 'id'])->inverseOf('user');
    }

    /**
     * @param $insert
     * @param $changedAttributes
     * @throws InvalidConfigException
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if ($insert) {
            if (!$this->person) {
                $this->person = Yii::createObject(Person::class);
            }

            if (\Yii::$app->id === 'app-frontend') {
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
    public function setRefovod(): void
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
    public function setReferrer(): void
    {
        $service = new CookieService([
            'name' => $this->cookieParams['referrer']['name'],
        ]);
        $this->person->referrer = $service->getValue();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTodo()
    {
        return $this->hasMany(Todo::class, ['user_id' => 'id']);
    }

}
