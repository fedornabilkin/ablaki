<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.10.2020
 * Time: 14:33
 */

namespace console\controllers;

use common\services\game\GameCreateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CronController extends Controller
{
    public function actionIndex()
    {
        Yii::error('run console action' . json_encode($this->request));
        return ExitCode::OK;
    }

    public function actionUserClear(): int
    {
        // Keep the deployed daily entry point, using the owner's selected rating policy.
        return $this->actionInactiveRating();
    }

    public function actionGameCreate(): int
    {
        (new GameCreateService())->execute();
        return ExitCode::OK;
    }

    public function actionInactiveRating(): int
    {
        $count = (new \common\services\user\InactiveRatingService(Yii::$app->db))->run();
        $this->stdout('Обработано неактивных пользователей: ' . $count . PHP_EOL);
        return ExitCode::OK;
    }
}
