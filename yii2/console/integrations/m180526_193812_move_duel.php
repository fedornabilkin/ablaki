<?php

use common\models\games\GameDuel;
use yii\db\Migration;

/**
 * Class m180526_193812_move_duel
 */
class m180526_193812_move_duel extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM duel ORDER BY id ASC LIMIT 100;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var GameDuel $model */
            $model = Yii::createObject(GameDuel::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }

            $model->id = $row['id'];
            $model->user_id = $row['user_creat'];
            $model->user_gamer = $row['user_gaming'];
            $model->kon = $row['kon'];
            $model->u1 = $row['u1'];
            $model->u2 = $row['u2'];
            $model->b1 = $row['b1'];
            $model->b2 = $row['b2'];
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
        GameDuel::deleteAll();
        $this->db->createCommand('ALTER TABLE '.GameDuel::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
