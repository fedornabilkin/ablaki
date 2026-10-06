<?php
namespace common\modules\world\controllers;

class TemplateController extends CrudController
{
    public $modelClass = \common\modules\world\models\NodeTemplate::class;
    public $searchClass = \common\modules\world\models\TemplateSearch::class;
    public $title = 'Шаблоны объектов';
    public $columns = ['id', 'name', 'code', 'hierarchy_level', 'build_seconds', 'enabled'];
    public $fields = ['code', 'name', 'kind', 'hierarchy_level', 'parentIds', 'build_seconds', 'materials_json', 'defaults_json', 'enabled', 'player_buildable'];
}
