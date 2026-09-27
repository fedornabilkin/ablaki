<?php
namespace backend\controllers;

class WorldPlotController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'PLOT'; }
}
