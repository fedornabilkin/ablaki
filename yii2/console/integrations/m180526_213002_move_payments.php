<?php

use common\models\Payments;

/**
 * Class m180526_213002_move_payments
 */
class m180526_213002_move_payments extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM payments ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        $this->exception['user'] = [0,35];

        foreach ($rows as $row){
            if($this->exceptionRow('user', $row['user'])) {
                continue;
            }

            /** @var Payments $model */
            $model = Yii::createObject(Payments::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->amount = $row['amount'];
            $model->wmz = $row['wmz'];
            $model->paysystem = $row['paysystem'];
            $model->type = $row['type'];
            $model->status = $row['status'];
            $model->buy_sell = $row['buy_sell'];
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
        Payments::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Payments::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
