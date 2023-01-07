<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 26.05.2018
 * Time: 16:24
 */

namespace common\helpers;


class UserHelper extends AbstractHelper
{

    /**
     * Возвращает массив классов CSS для оформления на основе рейтинга
     *
     * @param float $rating
     * @return array
     */
    public static function ratingStar(float $rating = 0.0)
    {
        $star['star_class'] = 'text-default';
        switch (true) {
            case $rating >= 500:
                $star['star_class'] = 'text-danger';
                break;
            case $rating >= 250:
                $star['star_class'] = 'text-warning';
                break;
            case $rating >= 100:
                $star['star_class'] = 'text-success';
                break;
            case $rating >= 50:
                $star['star_class'] = 'text-info';
                break;
            case $rating >= 10:
                $star['star_class'] = 'text-primary';
                break;
            case $rating >= 5:
                $star['star_class'] = 'text-primary';
                break;
        }
        return $star;
    }

    /**
     * Возвращает нормализованный рейтинг. Больше рейтинг, меньше дробных
     *
     * @param float $rating
     * @return float|int
     */
    public static function ratingRound(float $rating = 0.0): float
    {
        $rating = round($rating, 4);

        switch (true) {
            case $rating >= 100:
                $rating = round($rating);
            case $rating >= 50:
                $rating = round($rating, 1);
            case $rating >= 10:
                $rating = round($rating, 2);
        }
        return $rating;
    }
}
