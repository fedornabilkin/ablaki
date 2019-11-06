<?php

use common\models\user\Person;
use dektrium\user\models\User;

/**
 * Class m180526_160900_move_users
 */
class m180526_160900_move_users extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM users ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

//        $this->exception['mail'] = ['fedornabilkin@yandex.ru', 'ramses21@rambler.ru'];

        foreach ($rows as $user){
//            if($this->exceptionRow($user['mail'])){
//                continue;
//            }

//            $model = new User();
            /** @var User $model */
            $model = Yii::createObject(User::class);

            if (!$user['time']) {
                $user['time'] = strtotime($user['date']);
            }
            if($user['last_login']){
                $user['latest_activity'] = $user['last_login'];
            }
            if (!$user['latest_activity']) {
                $user['latest_activity'] = $user['time'];
            }
            if(!$user['mail']){
                $user['mail'] = $user['id'] . '_mail@mail.ru';
            }

            $model->id = $user['id'];
            $model->username = $user['login'];
            $model->email = $user['mail'];
            $model->registration_ip = $user['ip'];
            $model->created_at = $user['time'];
            $model->last_login_at = $user['latest_activity'];

            $model->save(false);

//            var_dump($model->person);exit;
            $model->person->balance = $user['balance'];
            $model->person->balance_in = $user['balans_in'];
            $model->person->balance_out = $user['balans_out'];
            $model->person->credit = $user['credit'];
            $model->person->refovod = $user['refovod'];
            $model->person->rating = $user['rating'];
            $model->person->referrer = $user['referer'];
            $model->person->bonus_count = $user['bonus_count'];
            $model->person->autoriz = $user['autoriz'];
            $model->person->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        User::deleteAll();
        $this->db->createCommand('ALTER TABLE '.User::tableName().' AUTO_INCREMENT = 1;')->execute();

        Person::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Person::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
