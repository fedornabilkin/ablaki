<?php

namespace common\modules\games\models;

use common\helpers\App;
use Yii;
use yii\db\ActiveQuery;

/**
 * Раунд партии «5 яблок».
 *
 * user_ball — скрытый ход создателя, gamer_ball — ответ соперника (0 = ждём).
 * user_amount / gamer_amount — очки, начисленные за раунд.
 * status: wait — ждём ответ соперника; draw / user / gamer — итог раунда.
 *
 * This is the model class for table "game_five_hod".
 *
 * @property int $id
 * @property int $game_five_id
 * @property int $user_id
 * @property int $user_gamer
 * @property int $user_ball
 * @property int $gamer_ball
 * @property int $user_amount
 * @property int $gamer_amount
 * @property string $status
 * @property int $created_at
 *
 * @property GameFive $gameFive
 */
class GameFiveHod extends AbstractGame
{
    /**
     * В таблице нет updated_at — родительский TimestampBehavior его бы записал.
     */
    public function behaviors()
    {
        return [
            'TimestampBehavior' => [
                'class' => \yii\behaviors\TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    const STATUS_WAIT = 'wait';
    const STATUS_DRAW = 'draw';
    const STATUS_USER = 'user';
    const STATUS_GAMER = 'gamer';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_five_hod';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['game_five_id', 'user_id', 'user_gamer', 'user_amount', 'gamer_amount', 'created_at'], 'integer'],
            [['user_ball'], 'required'],
            [['user_ball'], 'integer', 'min' => GameFive::MIN_BALL, 'max' => GameFive::MAX_BALL],
            [['gamer_ball'], 'integer', 'min' => 0, 'max' => GameFive::MAX_BALL],
            [['status'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('games', 'ID'),
            'game_five_id' => Yii::t('games', 'Game Five ID'),
            'user_id' => Yii::t('games', 'User ID'),
            'user_gamer' => Yii::t('games', 'User Gamer'),
            'user_ball' => Yii::t('games', 'User Ball'),
            'gamer_ball' => Yii::t('games', 'Gamer Ball'),
            'user_amount' => Yii::t('games', 'User Amount'),
            'gamer_amount' => Yii::t('games', 'Gamer Amount'),
            'status' => Yii::t('games', 'Status'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getGameFive(): ActiveQuery
    {
        return $this->hasOne(GameFive::class, ['id' => 'game_five_id']);
    }

    public function isWait(): bool
    {
        return $this->status === self::STATUS_WAIT;
    }

    /**
     * Итог раунда по ходам обоих игроков.
     * @return string STATUS_DRAW | STATUS_USER | STATUS_GAMER
     */
    public function getWinnerStatus(): string
    {
        $user = (int)$this->user_ball;
        $gamer = (int)$this->gamer_ball;

        if ($user === $gamer) {
            return self::STATUS_DRAW;
        }

        // разница ровно в 1 — выигрывает меньшее число, иначе большее
        if (abs($user - $gamer) === 1) {
            return $user < $gamer ? self::STATUS_USER : self::STATUS_GAMER;
        }

        return $user > $gamer ? self::STATUS_USER : self::STATUS_GAMER;
    }

    /**
     * Очки за раунд: сумма при разнице в 1, иначе разность.
     */
    public function getWinAmount(): int
    {
        $user = (int)$this->user_ball;
        $gamer = (int)$this->gamer_ball;

        if ($user === $gamer) {
            return 0;
        }

        $diff = abs($user - $gamer);

        return $diff === 1 ? $user + $gamer : $diff;
    }

    public function fields(): array
    {
        $fields = [
            'id',
            'game_five_id',
            'gamer_ball',
            'user_amount',
            'gamer_amount',
            'status',
            'created_at',
        ];

        // скрытый ход создателя виден после розыгрыша раунда или самому создателю
        if (!$this->isWait()) {
            $fields[] = 'user_ball';
        } elseif (!App::user()->getIsGuest() && (int)App::user()->getId() === (int)$this->user_id) {
            $fields[] = 'user_ball';
        }

        return $fields;
    }
}
