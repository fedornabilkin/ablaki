<?php

use frontend\modules\advertising\models\Advertising;

/**
 * Class m180727_041115_move_advertising
 */
class m180727_041115_move_advertising extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $sql = "SELECT * FROM adverts ORDER BY id ASC;";
        $rows = $this->getRemoteRows($sql);

        foreach ($rows as $row){

            /** @var Advertising $model */
            $model = Yii::createObject(Advertising::class);

            $time = strtotime($row['date']);

            $model->id = $row['id'];
            $model->user_id = $row['user'];
            $model->title = $row['name'];
            $model->description = $row['desc'];
            $model->approve = $row['approve'];
            $model->status = $row['status'];
            $model->url = $row['url'];
            $model->banner = $row['banner'];
            $model->position = $row['position'];
            $model->hash = $row['hex'];
            $model->credit = $row['amount'];
            $model->type = $row['type'];
            $model->clicks = $row['clicks'];
            $model->views = $row['views'];
            $model->comment = $row['comment'];
            $model->updated_at = $time;
            $model->created_at = $time;

            $model->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        Advertising::deleteAll();
        $this->db->createCommand('ALTER TABLE '.Advertising::tableName().' AUTO_INCREMENT = 1;')->execute();
    }
}
