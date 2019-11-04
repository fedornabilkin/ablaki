<?php

use common\models\Todo;

/**
 * Class m180526_213021_move_todo
 */
class m180526_213021_move_todo extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM todo ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var Todo $model */
            $model = Yii::createObject(Todo::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->title = $row['title'];
            $model->comment = $row['comment'];
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
        Todo::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Todo::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
