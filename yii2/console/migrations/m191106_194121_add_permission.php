<?php

use yii\db\Migration;

/**
 * Class m191106_194121_add_permission
 */
class m191106_194121_add_permission extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        Yii::$app->db->createCommand()->batchInsert('auth_item',
            ['name', 'type', 'description', 'created_at', 'updated_at'],
            [
                ['admin', 1, 'Все видит.', 1573065859, 1573065859],
                ['user', 2, 'Пользователи.', 1573065859, 1573065859],
                ['redirect', 2, 'Перенаправления.', 1573065859, 1573065859],
                ['/user/*', 2, '', 1573065859, 1573065859],
                ['/admin/*', 2, '', 1573065859, 1573065859],
                ['/redirect/*', 2, '', 1573065859, 1573065859],
                ['/binds/*', 2, '', 1573065859, 1573065859],
            ]
        )->execute();

        Yii::$app->db->createCommand()->batchInsert('auth_item_child', ['parent', 'child'], [
            ['user', '/user/*'],
            ['redirect', '/redirect/*'],
            ['admin', '/admin/*'],
            ['admin', 'user'],
            ['admin', 'redirect'],
        ])->execute();

        Yii::$app->db->createCommand()->batchInsert('auth_assignment', ['item_name', 'user_id', 'created_at'], [
            ['admin', 1, 1573066901],
        ])->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        Yii::$app->db->createCommand('DELETE auth_assignment')
            ->execute();
        Yii::$app->db->createCommand('DELETE auth_item_child')
            ->execute();
        Yii::$app->db->createCommand('DELETE auth_item')
            ->execute();
    }
}
