<?php
namespace backend\controllers;

class WorldRegionController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'REGION'; }
}
