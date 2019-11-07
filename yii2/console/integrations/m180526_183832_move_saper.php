<?php

use common\models\games\GameSaper;

/**
 * Class m180526_183832_move_saper
 */
class m180526_183832_move_saper extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM ablaki ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var GameSaper $model */
            $model = Yii::createObject(GameSaper::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }
            if (!$row['start_time']) {
                $row['start_time'] = strtotime($row['start_game']);
            }
            if (!$row['over_time']) {
                $row['over_time'] = strtotime($row['over_game']);
            }

            $model->id = $row['id'];
            $model->user_id = $row['user_creat'];
            $model->user_gamer = $row['user_gaming'] ?? 0;
            $model->kon = $row['kon'];
            $model->kon_double = $row['double_kon'];
            $model->etap = $row['etap'];
            $model->pole1 = $row['pole1'];
            $model->pole2 = $row['pole2'];
            $model->pole3 = $row['pole3'];
            $model->pole4 = $row['pole4'];
            $model->pole5 = $row['pole5'];
            $model->hod1 = $row['hod1'];
            $model->hod2 = $row['hod2'];
            $model->hod3 = $row['hod3'];
            $model->hod4 = $row['hod4'];
            $model->hod5 = $row['hod5'];
            $model->time_start_at = $row['start_time'];
            $model->time_over_at = $row['over_time'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        GameSaper::deleteAll();
        $this->db->createCommand('ALTER TABLE '.GameSaper::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
