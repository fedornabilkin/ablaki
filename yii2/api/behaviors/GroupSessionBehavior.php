<?php


namespace api\behaviors;


use api\actions\AllAndOneIndexAction;
use api\events\ActionBeforeFindEvent;
use common\models\GroupSession;
use yii\base\Behavior;
use yii\db\ActiveRecord;

class GroupSessionBehavior extends Behavior
{

    public function events()
    {
        if ($this->owner instanceof ActiveRecord) {
            return [
                ActiveRecord::EVENT_BEFORE_INSERT => [$this, 'beforeInsert']
            ];
        }

        if ($this->owner instanceof AllAndOneIndexAction) {
            return [
                AllAndOneIndexAction::EVENT_BEFORE_FIND => [$this, 'beforeFind']
            ];
        }
    }


    public function beforeInsert($event)
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;

        if (!$model->hasErrors()) {
            $model->groupSession = GroupSession::findOrCreate();
        }
    }

    /**
     * @param ActionBeforeFindEvent $event
     */
    public function beforeFind($event)
    {
        $event->query->joinWith(['groupSession']);
    }

}