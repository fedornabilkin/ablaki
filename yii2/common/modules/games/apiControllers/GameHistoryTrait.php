<?php

namespace common\modules\games\apiControllers;

use api\components\ApiList;
use common\helpers\App;
use common\models\user\User;
use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;
use common\modules\games\service\GameOverview;
use common\modules\games\service\HistoryPeriod;
use Yii;
use yii\data\ActiveDataProvider;
use yii\web\BadRequestHttpException;

/** Shared history scope for both rows and grouped stake options. */
trait GameHistoryTrait
{
    private function historyQuery(string $scope = 'history')
    {
        $query = $this->modelClass::find();
        $saper = is_a($this->modelClass, GameSaper::class, true);
        $hasRecent = $saper || is_a($this->modelClass, GameOrel::class, true);
        if ($scope === 'history') $query->listHistory(App::user()->identity);
        elseif ($scope !== 'recent' || !$hasRecent) throw new BadRequestHttpException('Invalid history scope.');
        if ($hasRecent) GameOverview::completed($query, $saper);
        HistoryPeriod::apply($query, $saper ? 'time_over_at' : 'updated_at', Yii::$app->request->get('period', 'all'));
        return $query;
    }

    public function actionHistoryKons(): array
    {
        $scope = Yii::$app->request->get('scope', 'history');
        if (!is_string($scope)) throw new BadRequestHttpException('Invalid history scope.');
        return $this->historyQuery($scope)->select(['kon', 'COUNT(*) AS count'])
            ->limit(null)->offset(null)->groupBy(['kon'])->orderBy(['kon' => SORT_ASC])->asArray()->all();
    }

    private function prepareHistoryList(string $scope = 'history'): ActiveDataProvider
    {
        $query = $this->historyQuery($scope)->with(['user.person', 'userGamer.person']);
        $filter = Yii::$app->request->get('filter', []);
        if (!is_array($filter)) throw new BadRequestHttpException('Invalid game filter.');
        if (isset($filter['kon']) && $filter['kon'] !== '') {
            $stake = $filter['kon'];
            if (!is_scalar($stake) || !is_numeric($stake) || !is_finite((float)$stake) || (float)$stake <= 0) {
                throw new BadRequestHttpException('Invalid stake.');
            }
            $query->andWhere(['kon' => $stake]);
        }
        $dateColumn = is_a($this->modelClass, GameSaper::class, true) ? 'time_over_at' : 'updated_at';
        $provider = ApiList::provider($query, [], ['id', 'created_at', $dateColumn, 'kon'],
            static function ($query, $search) {
                $users = User::find()->select('id');
                ApiList::search($users, ['username'], $search);
                $condition = ['or', ['in', 'user_id', clone $users], ['in', 'user_gamer', $users]];
                if (ctype_digit($search)) $condition[] = ['id' => $search];
                $query->andWhere($condition);
            });
        $provider->getSort()->defaultOrder = [$dateColumn => SORT_DESC, 'id' => SORT_DESC];
        return $provider;
    }
}
