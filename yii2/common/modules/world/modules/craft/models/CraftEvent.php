<?php
namespace common\modules\world\modules\craft\models;

class CraftEvent extends \yii\db\ActiveRecord
{
    public static function tableName() { return 'craft_event'; }
    public static function history(int $user) { return self::find()->where(['user_id' => $user]); }
    public static function requireSchema(): void
    {
        if (!self::getDb()->schema->getTableSchema('craft_command', true) || !self::getDb()->schema->getTableSchema('craft_tool_wear', true))
            throw new \yii\web\HttpException(503, 'Мастерская обновляется. Попробуйте немного позже.');
    }
}
