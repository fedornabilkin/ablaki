<?php

use common\models\CreditExchange;
use yii\db\Migration;

/**
 * Class m180526_212833_move_exchange
 */
class m180526_212833_move_exchange extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM exchange ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var CreditExchange $model */
            $model = Yii::createObject(CreditExchange::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->type = $row['type'];
            $model->credit = $row['credit'];
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
        CreditExchange::deleteAll();
        $this->db->createCommand('ALTER TABLE '.CreditExchange::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
