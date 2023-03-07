<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.03.2023
 * Time: 21:02
 */

namespace common\components\logger;

use common\helpers\App;

class FileTarget extends \yii\log\FileTarget
{

    /**
     * @return string
     */
    protected function getContextMessage(): string
    {
        $result = parent::getContextMessage();

        $request = App::request();

        if (!$request->getIsConsoleRequest()) {
            $result .= PHP_EOL . PHP_EOL . 'php://input = ' . var_export($request->post(), true);
        }

        return $result;
    }

    /**
     * @param array $message
     *
     * @return string|string[]
     */
    public function formatMessage($message)
    {
        return str_replace("\n", ' ', parent::formatMessage($message));
    }
}
