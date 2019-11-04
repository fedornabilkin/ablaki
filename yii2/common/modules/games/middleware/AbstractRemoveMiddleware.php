<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 15:51
 */

namespace common\modules\games\middleware;

class AbstractRemoveMiddleware extends GameMiddleware
{
    /**
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    public function check(): bool
    {
        $this->execute();

        return parent::check();
    }

    /**
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function execute(): void
    {
        $this->remove();
        $this->updateData();
    }

    /**
     * @inheritdoc
     */
    public function updateData(): void
    {
        self::$data->historyType = self::$data->game::HISTORY_TYPE;
        self::$data->historyComment = 'Remove the game #' . self::$data->game->id;
    }

    /**
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    protected function remove(): bool
    {
        return self::$data->game->delete();
    }
}
