<?php

namespace common\modules\games\models;

use common\helpers\App;
use common\models\core\ModelQueryTrait;
use common\models\history\HistorySaveInterface;
use common\models\history\HistoryTypeTrait;
use Yii;

/**
 * Игра «Дуэль» — одна схватка.
 *
 * Каждый игрок выбирает удар по противнику и блок для себя по зонам:
 * голова (1), корпус (2), ноги (3). Удар проходит, если бьёт в зону,
 * которую противник не закрыл блоком.
 *
 * Попал только один — он забирает банк (две ставки). Попали оба или
 * оба удара пришлись в блок — ничья, ставки возвращаются.
 *
 * u1/b1 — удар и блок создателя (скрыты до розыгрыша), u2/b2 — соперника.
 *
 * This is the model class for table "game_duel".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property double $kon
 * @property int $u1
 * @property int $u2
 * @property int $b1
 * @property int $b2
 * @property int $updated_at
 * @property int $created_at
 */
class GameDuel extends AbstractGame implements HistorySaveInterface
{
    use ModelQueryTrait;
    use HistoryTypeTrait;

    protected $historyType = 'game_duel';

    public $count = 1;

    const SCENARIO_PLAY = 'play';

    const ZONE_HEAD = 1;
    const ZONE_BODY = 2;
    const ZONE_LEGS = 3;

    const STATUS_DRAW = 'draw';
    const STATUS_USER = 'user';
    const STATUS_GAMER = 'gamer';

    const COMMISSION_RATE = 0.05;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_duel';
    }

    /**
     * @return array
     */
    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PLAY] = ['u2', 'b2'];
        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_gamer', 'updated_at', 'created_at'], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number', 'min' => 1],
            [['u1', 'b1'], 'required'],
            [['u2', 'b2'], 'required', 'on' => self::SCENARIO_PLAY],
            [['u1', 'u2', 'b1', 'b2'], 'integer', 'min' => self::ZONE_HEAD, 'max' => self::ZONE_LEGS],
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
            'kon' => Yii::t('games', 'Kon'),
            'u1' => Yii::t('games', 'User Udar'),
            'u2' => Yii::t('games', 'Gamer Udar'),
            'b1' => Yii::t('games', 'User Blok'),
            'b2' => Yii::t('games', 'Gamer Blok'),
            'updated_at' => Yii::t('games', 'Updated At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    public function isFinished(): bool
    {
        return (int)$this->user_gamer > 0;
    }

    public function isCreator(int $userId): bool
    {
        return (int)$this->user_id === $userId;
    }

    /**
     * Удар создателя прошёл — соперник не закрыл эту зону.
     */
    public function isCreatorHit(): bool
    {
        return (int)$this->u1 !== (int)$this->b2;
    }

    /**
     * Удар соперника прошёл — создатель не закрыл эту зону.
     */
    public function isGamerHit(): bool
    {
        return (int)$this->u2 !== (int)$this->b1;
    }

    /**
     * Исход схватки. Попал только один — он победил, иначе ничья.
     * @return string STATUS_DRAW | STATUS_USER | STATUS_GAMER
     */
    public function getWinnerStatus(): string
    {
        $creatorHit = $this->isCreatorHit();
        $gamerHit = $this->isGamerHit();

        if ($creatorHit === $gamerHit) {
            return self::STATUS_DRAW;
        }

        return $creatorHit ? self::STATUS_USER : self::STATUS_GAMER;
    }

    /**
     * Банк схватки — две ставки.
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
        $fields = [
            'id',
            'user_id',
            'username' => static function (self $model) {
                return $model->user ? $model->user->username : null;
            },
            'username_gamer' => static function (self $model) {
                return $model->userGamer ? $model->userGamer->username : null;
            },
            'kon',
            'created_at',
            'updated_at',
        ];

        // удар и блок создателя скрыты до розыгрыша (видны только ему)
        if ($this->isFinished()) {
            $fields[] = 'u1';
            $fields[] = 'u2';
            $fields[] = 'b1';
            $fields[] = 'b2';
            $fields['result'] = static function (self $model) {
                return $model->getWinnerStatus();
            };
        } elseif (!App::user()->getIsGuest() && (int)App::user()->getId() === (int)$this->user_id) {
            $fields[] = 'u1';
            $fields[] = 'b1';
        }

        return $fields;
    }
}
