<?php

use common\models\LogIp;

/**
 * Class m180526_212944_move_log_ip
 */
class m180526_212944_move_log_ip extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM log_ip ORDER BY id_logip ASC;";
        $rows = $this->getRemoteRows($sql);

        $this->exception['id_user'] = [0,35,825,8487];

        foreach ($rows as $row){
            if($this->exceptionRow('id_user', $row['id_user'])) {
                continue;
            }

            /** @var LogIp $model */
            $model = Yii::createObject(LogIp::class);

            if (!$row['time']) {
                $row['time'] = strtotime($row['date']);
            }

            $model->id = $row['id_logip'];
            $model->user_id = $row['id_user'];
            $model->ip = $row['ip_user'];
            $model->agent = $row['agent_user'];
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
        LogIp::deleteAll();
        $this->db->createCommand('ALTER TABLE '.LogIp::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
