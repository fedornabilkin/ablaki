<?php

use common\models\CreditTransfer;

/**
 * Class m180526_213027_move_transfer
 */
class m180526_213027_move_transfer extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM transfer ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var CreditTransfer $model */
            $model = Yii::createObject(CreditTransfer::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->recepient = $row['recepient'];
            $model->amount = $row['amount'];
            $model->password = $row['hash'];
            $model->updated_at = $row['recep_time'];
            $model->created_at = $row['time'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        CreditTransfer::deleteAll();
        $this->db->createCommand('ALTER TABLE '.CreditTransfer::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
