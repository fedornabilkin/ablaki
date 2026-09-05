<?php

namespace common\modules\games\apiControllers;

use api\components\ApiList;
use common\helpers\App;
use common\models\user\User;
use common\modules\games\models\GameSaper;
use common\modules\games\service\GameOverview;
use Yii;

trait GameListsTrait
{
    private function configureGameLists(array $actions): array
    {
        $index = $actions['index'];
        $index['dataFilter'] = $this->getFilter();
        foreach (['index', 'my', 'history', 'recent'] as $mode) {
            $actions[$mode] = $index;
            $actions[$mode]['prepareDataProvider'] = function ($action, $filter) use ($mode) {
                $query = $this->modelClass::find()->with(['user', 'userGamer']);
                if ($mode === 'my') {
                    $query->listMyGame(App::user()->identity);
                } elseif ($mode === 'history') {
                    $query->listHistory(App::user()->identity);
                    GameOverview::completed($query, is_a($this->modelClass, GameSaper::class, true));
                } elseif ($mode === 'recent') {
                    GameOverview::completed($query, is_a($this->modelClass, GameSaper::class, true));
                } else {
                    $query->listGame(App::user()->identity);
                }
                $query->andFilterWhere($filter === null ? [] : $filter);
                $dateColumn = is_a($this->modelClass, GameSaper::class, true) ? 'time_over_at' : 'updated_at';
                return ApiList::provider($query, [], ['id', 'created_at', $dateColumn, 'kon'],
                    static function ($query, $search) {
                        $users = User::find()->select('id');
                        ApiList::search($users, ['username'], $search);
                        $condition = ['or', ['in', 'user_id', clone $users], ['in', 'user_gamer', $users]];
                        if (ctype_digit($search)) {
                            $condition[] = ['id' => $search];
                        }
                        $query->andWhere($condition);
                    });
            };
        }
        return $actions;
    }

    public function actionSummary(): array
    {
        return GameOverview::summary($this->modelClass, App::user()->getId(), Yii::$app->timeZone);
    }
}
