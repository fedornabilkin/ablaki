<?php

use common\models\games\GameFive;

/**
 * Class m180526_212858_move_five
 */
class m180526_212858_move_five extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM five_apples ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var GameFive $model */
            $model = Yii::createObject(GameFive::class);

            $row['time'] = strtotime($row['date']);

            $model->id = $row['id'];
            $model->user_id = $row['user_creat'];
            $model->user_gamer = $row['user_gaming'];
            $model->user_amount = $row['user_creat_amount'];
            $model->gamer_amount = $row['user_gaming_amount'];
            $model->kon = $row['kon'];
            $model->status = $row['status'];
            $model->updated_at = $row['time_over'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        GameFive::deleteAll();
        $this->db->createCommand('ALTER TABLE '.GameFive::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
