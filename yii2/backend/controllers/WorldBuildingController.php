<?php
namespace backend\controllers;

class WorldBuildingController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'BUILDING'; }
}
