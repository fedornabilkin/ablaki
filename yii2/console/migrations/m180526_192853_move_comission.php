<?php

use common\models\Commission;

/**
 * Class m180526_192853_move_comission
 */
class m180526_192853_move_comission extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM comission ORDER BY id ASC LIMIT 100;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var Commission $model */
            $model = Yii::createObject(Commission::class);

            $model->id = $row['id'];
            $model->type = $row['type'];
            $model->amount = $row['amount'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        Commission::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Commission::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
