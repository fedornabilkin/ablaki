<?php

namespace common\modules\games\models;

use common\models\user\Person;
use common\modules\games\models\repo\Orel;
use Yii;

/**
 * Class GameOrel
 * @package common\modules\games\models
 */
class GameOrel extends Orel
{
//    use PersonTrait;

    public $count = 1;

    const SCENARIO_PLAY = 'play';
    const HISTORY_TYPE = 'game_orel';

    /**
     * @return array
     */
    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PLAY] = ['hod'];
        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        $arr = [
            ['count', 'integer', 'min' => 1, 'max' => 100],
            ['hod', 'integer', 'on' => self::SCENARIO_PLAY],
        ];

        return array_merge(parent::rules(), $arr);
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
            'type' => Yii::t('games', 'Type'),
            'hod' => Yii::t('games', 'Hod'),
            'updated_at' => Yii::t('games', 'Updated At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    public function getHistoryType(): string
    {
        return self::HISTORY_TYPE;
    }

    /**
     * @return int
     */
    public function getRandomType(): int
    {
        return random_int(1, 2);
    }

    public function getCommissionAmount($amount): float
    {
        return $amount * 0.05;
    }

    /**
     * Если игрок победил = true
     * @return bool
     */
    public function isWin(): bool
    {
        return $this->type === (int)$this->hod;
    }

    /**
     * @param $person
     * @param float $kon
     * @return float|int
     */
    public function normalizeRating($person, float $kon = 0.0): float
    {
        if (!($person instanceof Person)) {
            return 0;
        }

        $rating = $person->rating;

        $kef = ($rating < 0.99) ? 1.9 : 0;
        $kon = ($kon < 1) ? $this->kon : $kon;

        return round(($kon / 50) / ($rating + $kef), 5);
    }

    public function fields(): array
    {
        return [
            'id',
            'username' => function (Orel $model) {
                return $model->user->username;
            },
            'user_gamer',
            'kon',
            'created_at',
            'updated_at',
        ];
    }
}
