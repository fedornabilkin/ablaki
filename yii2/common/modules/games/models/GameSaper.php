<?php

namespace common\modules\games\models;

use common\models\core\ModelQueryTrait;
use common\models\user\Person;
use common\modules\games\models\repo\Saper;
use Yii;

/**
 * Class GameSaper
 * @package common\modules\games\models
 */
class GameSaper extends Saper
{

    use ModelQueryTrait;

    public $count = 1;
    public $col;
    public $row;

    const GAME_SAPER_ETAP_NEW = 5;
    const GAME_SAPER_ETAP_WIN = 0;
    const GAME_SAPER_ETAP_LOSE = 10;

    const SCENARIO_CREATE = 'create';
    const SCENARIO_PLAY = 'play';
    const SCENARIO_DOUBLE = 'double';

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_CREATE] = ['user_id', 'kon', 'etap', 'pole1', 'pole2', 'pole3', 'pole4', 'pole5'];
        $scenarios[self::SCENARIO_PLAY] = ['hod1', 'hod2', 'hod3', 'hod4', 'hod5', 'col', 'row'];
        $scenarios[self::SCENARIO_DOUBLE] = ['kon_double'];
        return $scenarios;
    }

    public function getRandomType(): int
    {
        return random_int(1, 7);
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        $arr = [
            [['count'], 'integer'],

            [['col'], 'integer', 'min' => 1, 'max' => 7, 'on' => self::SCENARIO_PLAY, 'message' => Yii::t('games', 'Invalid value col')],
            [['row'], 'integer', 'min' => 1, 'max' => 5, 'on' => self::SCENARIO_PLAY, 'message' => Yii::t('games', 'Invalid value row')],
            // поле row всегда последнее, по нему проверяется завершение игры
//            [['hod1', 'hod2', 'hod3', 'hod4', 'hod5', 'col', 'row'], 'validatePlay', 'on' => self::SCENARIO_PLAY],
        ];

        return array_merge(parent::rules(), $arr);
    }

//    public function validateStart($attribute)
//    {
//        if($this->checkMyGame()){
//            if ($this->etap == self::GAME_SAPER_ETAP_WIN or $this->etap == self::GAME_SAPER_ETAP_LOSE) {
//                $this->addError('start', Yii::t('games', 'Game complete'));
//            }
//            if($this->oldAttributes['user_gamer'] > 0 && $this->oldAttributes['user_gamer'] == $this->user_gamer){
//                $this->addError('start', Yii::t('games', 'The game started'));
//            }
//        }
//    }


    /**
     * @param $attribute
     * @deprecated
     */
    public function validatePlay($attribute): void
    {
        if ($this->checkHod() && $attribute === 'row') {
            $this->checkComplete();
        }
    }

    /**
     * @deprecated
     */
    public function checkComplete()
    {
        $pole = 'pole' . $this->row;
        $hod = 'hod' . $this->row;

        $this->$hod = $this->col;
        // если победил создатель
        if ($this->$pole == $this->col) {
            $this->etap = self::GAME_SAPER_ETAP_LOSE;
//            $this->content['error'] = true;
//            $this->createrWin();
//            $this->content['row']['profit'] = 0;
        } else {
            // прошли ряд
//            $this->content['hod'] = true;
            if ($this->etap != self::GAME_SAPER_ETAP_WIN + 1) {
                $this->etap--;
//                $this->content['alert'] = 'Прошли';
//                return $this;
            }
            // победа
            if ($this->etap == self::GAME_SAPER_ETAP_WIN + 1) {
                $this->etap = self::GAME_SAPER_ETAP_WIN;
//                $this->gamerWin();
//                $this->content['row']['profit'] = $this->win_balance;
            }
        }
    }

    /**
     * Проверяет возможность хода
     *
     * @return bool
     * @deprecated
     */
    public function checkHod()
    {

        // Начните игру
        if ($this->user_gamer == 0 && $this->etap == self::GAME_SAPER_ETAP_NEW) {
            $this->addError('play', Yii::t('games', 'Start the game'));
            return false;
        }

        // завершена
        if ($this->etap == self::GAME_SAPER_ETAP_WIN or $this->etap == self::GAME_SAPER_ETAP_LOSE) {
            $this->addError('play', Yii::t('games', 'Game complete'));
            return false;
        }

        // предыдущий
        if ($this->row < $this->etap) {
            $this->addError('play', Yii::t('games', 'Previous row'));
            return false;
        }

        // следующий
        if ($this->row > $this->etap) {
            $this->addError('play', Yii::t('games', 'Next row'));
            return false;
        }

        return true;
    }

    public function getCommissionAmount(): float
    {
        return $this->kon * 2 * 0.05;
    }

    public function isComplete()
    {
        return $this->etap == self::GAME_SAPER_ETAP_WIN || $this->etap == self::GAME_SAPER_ETAP_LOSE;
    }

    public function isWin()
    {
        return $this->etap === self::GAME_SAPER_ETAP_WIN;
    }

    /**
     * @param Person $person
     * @param float $kon
     * @return float|int
     */
    public function normalizeRating(float $rating): float
    {
        $kef = ($rating < 0.99) ? 1.9 : 0;

        return round(($this->kon / 2) / ($rating + $kef), 5);
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
            'kon_double' => Yii::t('games', 'Kon Double'),
            'etap' => Yii::t('games', 'Etap'),
            'pole1' => Yii::t('games', 'Pole1'),
            'pole2' => Yii::t('games', 'Pole2'),
            'pole3' => Yii::t('games', 'Pole3'),
            'pole4' => Yii::t('games', 'Pole4'),
            'pole5' => Yii::t('games', 'Pole5'),
            'hod1' => Yii::t('games', 'Hod1'),
            'hod2' => Yii::t('games', 'Hod2'),
            'hod3' => Yii::t('games', 'Hod3'),
            'hod4' => Yii::t('games', 'Hod4'),
            'hod5' => Yii::t('games', 'Hod5'),
            'time_start_at' => Yii::t('games', 'Time Start At'),
            'time_over_at' => Yii::t('games', 'Time Over At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    public function fields(): array
    {
        return [
            'id',
            'user_id',
            'user_gamer',
            'username' => static function (Saper $model) {
                return $model->user->username;
            },
            'username_gamer' => static function (Saper $model) {
                return $model->userGamer->username;
            },
            'kon',
            'created_at',
        ];
    }

}
