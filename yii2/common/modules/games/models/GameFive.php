<?php

namespace common\modules\games\models;

use common\helpers\App;
use common\models\core\ModelQueryTrait;
use common\models\history\HistorySaveInterface;
use common\models\history\HistoryTypeTrait;
use Yii;
use yii\db\ActiveQuery;

/**
 * Игра «5 яблок» — партия до WIN_POINTS очков.
 *
 * Каждый раунд (GameFiveHod) оба игрока ставят от 1 до 5 яблок:
 * числа равны — раунд вничью; разница ровно в 1 — игрок с меньшим числом
 * получает сумму обоих чисел очками; иначе игрок с большим числом получает
 * разность. Кто первым набирает 21 очко — выигрывает партию и забирает банк
 * (две ставки kon) за вычетом комиссии.
 *
 * user_amount / gamer_amount — накопленные очки игроков.
 *
 * This is the model class for table "game_five".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property int $user_amount
 * @property int $gamer_amount
 * @property double $kon
 * @property string $status
 * @property int $updated_at
 * @property int $created_at
 *
 * @property GameFiveHod[] $gameFiveHods
 */
class GameFive extends AbstractGame implements HistorySaveInterface
{
    use ModelQueryTrait;
    use HistoryTypeTrait;

    protected $historyType = 'game_five';

    public $count = 1;

    /** @var int|null входящий ход игрока (1-5), в таблице не хранится */
    public $ball;

    const SCENARIO_PLAY = 'play';

    const STATUS_FREE = 'free';
    const STATUS_PLAY = 'play';
    const STATUS_USER = 'user';
    const STATUS_GAMER = 'gamer';

    const TURN_USER = 'user';
    const TURN_GAMER = 'gamer';

    const MIN_BALL = 1;
    const MAX_BALL = 5;

    /** Партия идёт до этого количества очков */
    const WIN_POINTS = 21;

    const COMMISSION_RATE = 0.05;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_five';
    }

    /**
     * @return array
     */
    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PLAY] = ['ball'];
        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_gamer', 'user_amount', 'gamer_amount', 'updated_at', 'created_at'], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number', 'min' => 1],
            [['status'], 'string', 'max' => 50],
            [['ball'], 'required'],
            [['ball'], 'integer', 'min' => self::MIN_BALL, 'max' => self::MAX_BALL],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('games', 'ID'),
            'user_id' => Yii::t('games', 'User ID'),
            'user_gamer' => Yii::t('games', 'User Gamer'),
            'user_amount' => Yii::t('games', 'User Points'),
            'gamer_amount' => Yii::t('games', 'Gamer Points'),
            'kon' => Yii::t('games', 'Kon'),
            'ball' => Yii::t('games', 'Ball'),
            'status' => Yii::t('games', 'Status'),
            'updated_at' => Yii::t('games', 'Updated At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getGameFiveHods(): ActiveQuery
    {
        return $this->hasMany(GameFiveHod::class, ['game_five_id' => 'id']);
    }

    /**
     * @return GameFiveHod|null
     */
    public function getLastHod()
    {
        return $this->getGameFiveHods()->orderBy(['id' => SORT_DESC])->limit(1)->one();
    }

    public function isFree(): bool
    {
        return $this->status === self::STATUS_FREE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_PLAY;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_USER, self::STATUS_GAMER], true);
    }

    public function isCreator(int $userId): bool
    {
        return (int)$this->user_id === $userId;
    }

    public function isGamer(int $userId): bool
    {
        return (int)$this->user_gamer === $userId;
    }

    /**
     * Чей сейчас ход: user | gamer | null (партия завершена).
     * В свободной игре ход за соперником — создатель уже сделал скрытый ход.
     * @return string|null
     */
    public function getTurn()
    {
        if ($this->isFinished()) {
            return null;
        }

        if ($this->isFree()) {
            return self::TURN_GAMER;
        }

        $hod = $this->getLastHod();
        if ($hod !== null && $hod->isWait()) {
            return self::TURN_GAMER;
        }

        return self::TURN_USER;
    }

    /**
     * Банк партии — две ставки.
     */
    public function getBankAmount(): float
    {
        return $this->kon * 2;
    }

    public function getCommissionAmount(): float
    {
        return round($this->getBankAmount() * self::COMMISSION_RATE, 2);
    }

    /**
     * @param float $rating
     * @return float
     */
    public function normalizeRating(float $rating): float
    {
        $kef = ($rating < 0.99) ? 1.9 : 0;

        return round(($this->kon / 50) / ($rating + $kef), 5);
    }

    public function fields(): array
    {
        return [
            'id',
            'user_id',
            'username' => static function (self $model) {
                return $model->user ? $model->user->username : null;
            },
            'username_gamer' => static function (self $model) {
                return $model->userGamer ? $model->userGamer->username : null;
            },
            'kon',
            'status',
            'user_points' => static function (self $model) {
                return (int)$model->user_amount;
            },
            'gamer_points' => static function (self $model) {
                return (int)$model->gamer_amount;
            },
            'turn' => static function (self $model) {
                return $model->getTurn();
            },
            'created_at',
            'updated_at',
        ];
    }

    public function extraFields(): array
    {
        return [
            'hods' => 'gameFiveHods',
        ];
    }
}
