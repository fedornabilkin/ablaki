<?php

use common\models\games\GameFiveHod;

/**
 * Class m180526_212905_move_five_hod
 */
class m180526_212905_move_five_hod extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM five_apples_hod ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        $this->exception['id'] = [3716];

        foreach ($rows as $row){

            if($this->exceptionRow('id', $row['id'])){
                continue;
            }

            /** @var GameFiveHod $model */
            $model = Yii::createObject(GameFiveHod::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }

            $model->id = $row['id'];
            $model->game_five_id = $row['game'];
            $model->user_id = $row['user_creat'];
            $model->user_gamer = $row['user_gaming'];
            $model->user_ball = $row['user_creat_ball'];
            $model->gamer_ball = $row['user_gaming_ball'];
            $model->user_amount = $row['user_creat_amount'];
            $model->gamer_amount = $row['user_gaming_amount'];
            $model->status = $row['status'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        GameFiveHod::deleteAll();
        $this->db->createCommand('ALTER TABLE '.GameFiveHod::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
