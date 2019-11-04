<?php

use common\models\LogErrors;
use yii\db\Migration;

/**
 * Class m180526_194316_move_log_errors
 */
class m180526_194316_move_log_errors extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM errors ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var LogErrors $model */
            $model = Yii::createObject(LogErrors::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
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
        LogErrors::deleteAll();
        $this->db->createCommand('ALTER TABLE '.LogErrors::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
