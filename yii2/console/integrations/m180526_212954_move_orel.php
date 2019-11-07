<?php

use common\models\games\GameOrel;
use yii\db\Migration;

/**
 * Class m180526_212954_move_orel
 */
class m180526_212954_move_orel extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM orel ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var GameOrel $model */
            $model = Yii::createObject(GameOrel::class);

            $hod = $type = 0;
            $arr = ['worm' => 1, 'apple' => 2];
            if($row['hod']){
                $hod = $arr[$row['hod']];
            }

            $model->id = $row['id'];
            $model->user_id = $row['user_creat'];
            $model->user_gamer = $row['user_gaming'];
            $model->kon = $row['kon'];
            $model->type = $arr[$row['type']];
            $model->hod = $hod;
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
        GameOrel::deleteAll();
        $this->db->createCommand('ALTER TABLE '.GameOrel::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
