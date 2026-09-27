<?php
namespace backend\controllers;

class WorldBedController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'BED'; }
}
