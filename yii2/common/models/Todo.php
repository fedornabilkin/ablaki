<?php

namespace common\models;

use Yii;

/**
 * This is the model class for table "todo".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $comment
 * @property int $status
 * @property int $updated_at
 * @property int $created_at
 */
class Todo extends \yii\db\ActiveRecord
{
    /**
     * @var int|string
     */

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'todo';
    }


    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'status', 'updated_at', 'created_at'], 'integer'],
            [['comment'], 'string'],
            [['title'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'title' => Yii::t('app', 'Title'),
            'comment' => Yii::t('app', 'Comment'),
            'status' => Yii::t('app', 'Status'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

//    public function beforeSave($insert)
//    {
//        $this->user_id  = Yii::$app->user->id;
//        return true;
//    }

    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {

            $this->user_id = Yii::$app->user->id;

            return true;
        }
        elseif ($this->user_id = Yii::$app->user->id) {
            return $this->user_id = Yii::$app->user->id;
        }
        return false;
    }


}
