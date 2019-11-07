<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 20:20
 */

namespace common\modules\games\behaviors\saper;


use yii\db\ActiveRecord;

class SaperBehavior extends AbstractGameBehavior
{


    /** @return array */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeUpdate',
        ];
    }

    /**
     * @return ActiveRecord
     */
    private function _getOwner()
    {
        return $this->owner;
    }

    /**
     *
     */
    public function beforeInsert() {

        $model = $this->_getOwner();

    }

    /**
     *
     */
    public function beforeUpdate() {

        $model = $this->_getOwner();

        if ($uid = $this->_getUidModel($model->uid)) {
            $uid->updated_at = time();
            $uid->save();
        }else{
            $this->beforeInsert();
        }
    }

    /**
     * Если юзер может начать игру, возвращает true
     *
     *
     * @return bool
     */
    public function validateStart()
    {
        // проверяем balance
        if ($this->content['row']['kon'] > App::$user->balance) {
            $this->content['alert'] = 'Недостаточо средств';
            return false;
        }

        if ($this->content['row']['etap'] == 0 or $this->content['row']['etap'] == 10) {
            $this->content['alert'] = 'Игра сыграна';
            return false;
        }

        if ($this->content['row']['user_gaming'] == App::$user->id && $this->content['row']['etap'] == 5) {
            $this->content['alert'] = 'Выполните первый ход';
            return false;
        }

        if (!$this->validateMy()) {
            return false;
        }
        $this->content['validateStart'] = true;
        return true;
    }

    public function validateMy()
    {
        return true;
    }

    public function validatePlay()
    {
        return true;
    }

    public function validateDouble()
    {
        return true;
    }

}