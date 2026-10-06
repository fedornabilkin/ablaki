<?php
namespace common\modules\world\models;

use common\modules\world\support\GameError;
use yii\db\ActiveRecord;

abstract class Record extends ActiveRecord
{
    protected static function requireTransaction(): void
    {
        if (!static::getDb()->getTransaction()) throw new \LogicException('A world command transaction is required.');
    }
    public function persist(): void
    {
        if (!$this->save()) throw new GameError('VALIDATION_FAILED', 'Проверьте поля.', 422, $this->getErrors());
    }

    public static function requireOne($condition)
    {
        $model = static::findOne($condition);
        if (!$model) throw new GameError('NOT_FOUND', 'Запись не найдена.', 404);
        return $model;
    }
}
