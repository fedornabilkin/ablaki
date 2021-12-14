<?php

use common\models\history\HistoryBalance;
use console\migrations\AbstractMigration;

/**
 * Class m180526_212922_move_history_balance
 */
class m180526_212922_move_history_balance extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM history_balance ORDER BY id ASC;";
//        $sql = "SELECT * FROM history_balance ORDER BY id ASC limit 50000;";
//        $sql = "SELECT * FROM history_balance WHERE user > '14500' AND user < '15000' ORDER BY id ASC limit 10000;";
        $rows = $this->getRemoteRows($sql);

        $this->exception['user'] = [
            14511,14519,14524,14535,14546,14547,14548,
            14550,14553,14560,14561,14565,14568,14569,
            14572,14573,14578,14580,14581,14587,14588,14589,
            14592,14593,14594,14596,14599,14600,14605,14606,
            14610,14614,14624,14628,14631,14633,
        ];

        foreach ($rows as $row){
            if($this->exceptionRow('user', $row['user'])) {
                continue;
            }

            /** @var HistoryBalance $model */
            $model = Yii::createObject(HistoryBalance::class);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->balance = $row['balance'];
            $model->credit = $row['credit'];
            $model->balance_up = $row['amount'];
            $model->credit_up = $row['amount_cr'];
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
        HistoryBalance::deleteAll();
        $this->db->createCommand('ALTER TABLE '.HistoryBalance::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
