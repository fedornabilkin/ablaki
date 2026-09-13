<?php

namespace common\modules\games\apiControllers;

use api\components\ApiList;
use common\helpers\App;
use common\models\user\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\web\BadRequestHttpException;

/** Read-only history shared by duel and five; participation scope stays in the model query. */
trait GameHistoryTrait
{
    private function prepareHistoryList(): ActiveDataProvider
    {
        $query = $this->modelClass::find()->with(['user', 'userGamer'])->listHistory(App::user()->identity);
        $filter = Yii::$app->request->get('filter', []);
        if (!is_array($filter)) throw new BadRequestHttpException('Invalid game filter.');
        if (isset($filter['kon']) && $filter['kon'] !== '') {
            $stake = $filter['kon'];
            if (!is_scalar($stake) || !is_numeric($stake) || !is_finite((float)$stake) || (float)$stake <= 0) {
                throw new BadRequestHttpException('Invalid stake.');
            }
            $query->andWhere(['kon' => $stake]);
        }
        $provider = ApiList::provider($query, [], ['id', 'created_at', 'updated_at', 'kon'],
            static function ($query, $search) {
                $users = User::find()->select('id');
                ApiList::search($users, ['username'], $search);
                $condition = ['or', ['in', 'user_id', clone $users], ['in', 'user_gamer', $users]];
                if (ctype_digit($search)) $condition[] = ['id' => $search];
                $query->andWhere($condition);
            });
        $provider->getSort()->defaultOrder = ['updated_at' => SORT_DESC, 'id' => SORT_DESC];
        return $provider;
    }
}
