<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 22.07.2018
 * Time: 1:22
 */

namespace common\utilities\widgets\gridview;

use yii\helpers\Html;

class AuditColumn extends AbstractColumn
{

    protected function makeCellContent($model)
    {
        return $this->getCellContent($model);
    }

    protected function getCellContent($model)
    {
        $text = Html::tag('a', '', [
            'href' => 'javascript:void(0)',
            'tabindex' => 0,
            'class' => 'fa fa-info-circle pointer',
            'data-toggle' => 'popover',
            'data-trigger' => 'focus',
//            'data-placement' => 'left',
            'data-html' => 'true',
            'data-title' => $this->getPopoverTitle($model),
            'data-content' => $this->getPopoverContent($this->getContentValues($model)),
        ]);

        $text .= ' '. $this->getFormatter()->asTime($model->created_at);

        return $text;
    }

    protected function getPopoverTitle($model)
    {
        return '#' . $model->id;
    }

    protected function getPopoverContent($values)
    {
        $formatter = function($parts){
            return sprintf(
                '<div class="small">%s %s</div>',
                $parts[0],
                $parts[1]
            );
        };

        $appender = function($accumulator, $value){
            return $accumulator . $value;
        };

        return array_reduce(array_map($formatter, $values), $appender, '');
    }

    protected function getContentValues($model)
    {
        return [
            'created_at' => [
                '<span class="fa fa-clock-o"></span>',
                $this->getFormatter()->asDateTime($model->created_at)
            ],
            'comment' => [
                '<span class="fa fa-comment-o"></span>',
                $model->comment,
            ],
        ];
    }

    protected function getFormatter()
    {
        return \Yii::$app->formatter;
    }
}