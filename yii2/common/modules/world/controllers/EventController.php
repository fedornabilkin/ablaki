<?php
namespace common\modules\world\controllers;

class EventController extends CrudController
{
    public $modelClass = \common\modules\world\models\WorldEvent::class;
    public $searchClass = \common\modules\world\models\EventSearch::class;
    public $title = 'События мира';
    public $columns = ['id', 'name', 'scope_node_id', 'status', 'starts_at:datetime', 'ends_at:datetime'];
    public $fields = ['name', 'description', 'scope_node_id', 'status', 'starts_at', 'ends_at'];
}
