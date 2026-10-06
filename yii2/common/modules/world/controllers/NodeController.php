<?php
namespace common\modules\world\controllers;

class NodeController extends CrudController
{
    public $modelClass = \common\modules\world\models\Node::class;
    public $searchClass = \common\modules\world\models\NodeSearch::class;
    public $title = 'Объекты мира';
    public $columns = ['id', 'name', 'parent_id', 'template_id', 'hierarchy_level', 'owner_user_id', 'status'];
    public $fields = ['name', 'code', 'slug', 'parent_id', 'template_id', 'owner_user_id', 'visibility'];
}
