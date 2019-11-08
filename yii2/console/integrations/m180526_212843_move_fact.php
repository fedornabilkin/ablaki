<?php

use common\models\Fact;
use yii\db\Migration;

/**
 * Class m180526_212843_move_fact
 */
class m180526_212843_move_fact extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM fact ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var Fact $model */
            $model = Yii::createObject(Fact::class);

            $model->id = $row['id'];
            $model->title = $row['title'];
            $model->type = $row['type'];
            $model->hide = $row['hide'];

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        Fact::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Fact::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
