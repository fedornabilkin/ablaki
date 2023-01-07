<?php

use common\models\history\HistoryRating;
use console\migrations\AbstractMigration;

/**
 * Class m180526_212930_move_history_rating
 */
class m180526_212930_move_history_rating extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM history_rating ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row) {

            /** @var HistoryRating $model */
            $model = Yii::createObject(HistoryRating::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->rating = $row['rating'];
            $model->type = $row['type'];
            $model->comment = $row['comment'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        HistoryRating::deleteAll();
        $this->db->createCommand('ALTER TABLE '.HistoryRating::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
