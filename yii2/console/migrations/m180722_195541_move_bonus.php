<?php

use common\models\Bonus;

/**
 * Class m180722_195541_move_bonus
 */
class m180722_195541_move_bonus extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM bonus ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var Bonus $model */
            $model = Yii::createObject(Bonus::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->amount = $row['amount'];
            $model->type = $row['type'];
            $model->updated_at = $row['time'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        Bonus::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Bonus::tableName().' AUTO_INCREMENT = 1;')->execute();
    }

}
