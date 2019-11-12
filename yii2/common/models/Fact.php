<?php

namespace common\models;

use Yii;

/**
 * This is the model class for table "fact".
 *
 * @property int $id
 * @property string $title
 * @property string $type
 * @property int $hide
 */
class Fact extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'fact';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [ ['title', 'hide', 'type'], 'required' ],
            [['hide'], 'integer'],
            [['title'], 'string', 'max' => 255],
            [['type'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'title' => Yii::t('app', 'Title'),
            'type' => Yii::t('app', 'Type'),
            'hide' => Yii::t('app', 'Hide'),
        ];
    }
}
